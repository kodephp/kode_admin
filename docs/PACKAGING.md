# 整系统打包（PHAR / 二进制 / 镜像）

把整个站点打成**单文件分发行**：一条命令出产物，目标机解压即用，无需 composer、无需装依赖。

引擎：`Kode\Framework\Packaging\Packager`（框架）→ `app/support/AppPackager.php`（本站继承层）
配置唯一事实源：`config/packaging.php` ｜ 命令：`pack:phar` / `pack:bin` / `pack:verify`
测试：`tests/AppPackagerTest.php`（45 项）

## 0. 框架 / 应用分工（v1.4.2 起）

打包属于**通用工程能力**，不是业务——引擎在框架层，本站只继承。

| 层 | 文件 | 职责 |
|---|---|---|
| **框架** `Kode\Framework\Packaging\Packager` | `vendor/kode/framework/src/Packaging/Packager.php` | 100% 通用：collect() 文件清单、三档打包壳、autoload 重写、verify() 18 项校验、`driver()` 子类派发 |
| **框架** `pack:phar` / `pack:bin` / `pack:verify` | `vendor/kode/framework/src/Console/Commands/` | 命令本体，`ConsoleServiceProvider` 注册；任何装了框架的项目开箱即用 |
| **本站** `app\support\AppPackager extends Packager` | `app/support/AppPackager.php` | 一个空壳继承类——只为给本站 `config/packaging.php['packager']` 留一个**继承锚点** |

派发链路（LSB，不写死子类）：

```
config/packaging.php['packager'] = 'app\support\AppPackager'
        ↓
Packager::driver()  →  static::config() 读 'packager' 键  →  返回 app\support\AppPackager::class
        ↓
命令层 Packager::driver()::pack($config)   // 类常量式静态调用，等价 AppPackager::pack()
```

改打包行为只改 `config/packaging.php`；换引擎实现（如加签名、加压缩档位）只需
新建一个 `extends Packager` 的类并改 `packager` 键，框架与命令零改动。
框架侧所有 `self::config()` / `self::projectRoot()` 均已改为 `static::`，否则子类配置不生效。

> 与插件级打包（`app/support/PluginPackager.php`，单插件 zip）职责分离——
> 本引擎只管「整个系统」，不碰插件打包。
> 插件**发现**的同款分工见 §6.9。

---

## 1. 三档形态

| 档位 | 命令 | 产物 | 目标机要求 | 平台 | 典型用途 |
|---|---|---|---|---|---|
| **A. PHAR** | `pack:phar` | `build/kode.phar`（≈25 MB） | 需 PHP ≥ 8.3 + `phar.readonly=0`（仅构建期） | 任意 | 开发/内网/有 PHP 环境的运维机 |
| **B. 二进制** | `pack:bin` | `build/kode.bin` | **无需 PHP**（内嵌 micro.sfx 静态运行时） | 仅 x86_64 Linux | 无 PHP 的服务器、交付第三方 |
| **C. 镜像** | `docker build` | Docker 镜像（内含 A 档 PHAR） | 需 Docker | 任意 Linux | 容器编排、多环境一致性 |

A / B 两档共用同一份 `collect()` 文件清单与同一套前置门禁——档位只决定「打包壳」，
不决定「进什么文件」；C 档是 A 档产物的搬运（镜像内只含 `build/kode.phar`，
不再次打包，详见 §5.1）。

---

## 2. 与其他方案对比

| 方案 | 做法 | 与本引擎的差异 |
|---|---|---|
| **webman `workerman build:bin`** | micro.sfx + phar 拼接，支持 custom-ini 头 | 本引擎沿用**同一二进制格式**（magic `\xfd\xf6\x69\xe6` + 网络序长度 + 正文），二进制档可与 webman 产物互换运行；PHAR 层则按本项目的文件选择/校验契约自建 |
| **手工 phar**（`php -d phar.readonly=0` + 逐文件 `addFile`） | 无 | 逐文件 `addFile()` 在 128M `memory_limit` 下、对 44 MB / 6000+ 文件的 vendor 会被 OOM 杀掉；本引擎走「暂存目录 + `buildFromDirectory()`」，512M 稳定完成 |
| **Composer 自带** | 无 | Composer 不打包应用，只管理依赖 |
| **Laravel/Yii 社区 phar** | phar + 框架引导 | 多依赖框架特定路径；本引擎不假设框架位置，入口链由 `must_include` 显式声明并校验 |

---

## 3. 命令

```bash
# PHAR（构建期需 phar.readonly=0，见 §6.1）
php -d phar.readonly=0 kode pack:phar            # 出包
kode pack:phar --list                            # 干跑：只打印将进包的文件清单，不写盘
kode pack:phar --json                            # 机器可读输出（CI 用）
kode pack:phar --name=release-20260912.phar      # 覆盖产物名（别名随之变化，见 §6.2）

# 二进制（仅 x86_64 Linux）
php -d phar.readonly=0 kode pack:bin             # 自动复用 build/kode.phar，缺失则先出包
kode pack:bin --sfx=/opt/php8.3.micro.sfx        # 离线指定 micro.sfx，绕过平台门禁
kode pack:bin --php=8.2 --json

# 校验（CI 门禁）
kode pack:verify                                 # 默认 build/kode.phar
kode pack:verify build/kode.bin                  # 按 basename 自动分档
kode pack:verify --json
```

`pack:verify` 退出码：`0` = 通过，`1` = 失败。校验项随档位不同（见 §6.5）。

---

## 4. 配置（`config/packaging.php`，唯一事实源）

改打包行为**只改这里**，不要在命令或引擎里散落硬编码。

| 键 | 默认 | 说明 |
|---|---|---|
| `build_dir` | `build` | 产物目录；相对项目根，**绝对路径会被直接使用** |
| `phar_filename` | `kode.phar` | PHAR 名。**必须等于 `mapPhar` 别名**（§6.2） |
| `bin_filename` | `kode.bin` | 二进制名；`verify` 按此 basename 判定档位 |
| `signature_algorithm` | `\Phar::SHA256` | 签名算法；`OPENSSL` 时需配 `openssl_private_key` |
| `openssl_private_key` | `null` | OPENSSL 签名私钥路径 |
| `memory_limit_mb` | `512` | 构建期抬高的内存下限（`memory_limit` 是 `PHP_INI_ALL`，可运行期提升） |
| `php_version` | `null`（回退当前 PHP 主.次） | 二进制内嵌运行时版本，决定下载哪个 micro.sfx |
| `micro_sfx_base` | workerman 官方分发前缀 | micro.sfx 下载源 |
| `include_dirs` | `app config database lang plugins public vendor` | 顶层目录白名单 |
| `include_files` | `kode composer.json composer.lock LICENSE README.md .env.example` | 顶层文件白名单 |
| `exclude_dirs` | dev 元数据 + phpunit 依赖树 | 子树黑名单（命中前缀整树丢弃） |
| `exclude_files` | `.DS_Store .gitkeep Thumbs.db` | 单文件黑名单（按 basename，任意层级） |
| `exclude_top_files` | `.env .gitignore phpunit.xml CHANGELOG.md Dockerfile …` | 顶层文件黑名单（按相对路径精确匹配） |
| `exclude_ext` | 图片/压缩/sqlite/log/tmp | 扩展名黑名单（大小写不敏感） |
| `must_include` | `kode vendor/autoload.php vendor/kode/framework/kode public/index.php config/app.php` | 包内必备入口链，缺任一项 → 构建失败 |
| `runtime_dirs` | `storage/logs …` | 运行期空目录，打包不建，仅用于部署文档与报告 |
| `custom_ini` | `null` | 二进制内嵌 php.ini 片段（换行分隔） |

**选择规则**：`include_dirs` / `include_files` 白名单决定起点 → 依次扣掉
`exclude_dirs` / `exclude_files` / `exclude_top_files` / `exclude_ext`。
扩展名黑名单对**顶层文件同样生效**——`include_files` 里有 `README.md`，
因此 `exclude_ext` 中切勿放 `md`。

---

## 5. 部署契约

打包产物**不含** `.env` 与 `storage/`，这是有意为之：

| 不进包 | 原因 | 部署方式 |
|---|---|---|
| `.env` | 含密钥 | 以环境变量提供（12-factor）；仓库保留 `.env.example` 进包 |
| `storage/` | 运行时数据（日志/缓存/会话/上传） | 首次运行在**工作目录**自动创建 |
| 用户上传文件 | `phar://` 操作上传文件有反序列化风险 | 必须落在包外工作目录 |

框架以 `Application` 的 `basePath`（= 运行工作目录）解析配置，在
`basePath/.env` 加载环境。因此：

```bash
# 部署：把产物 + .env 放同一目录即可
mkdir -p /var/www/kode && cd /var/www/kode
cp ~/kode.phar .
cp ~/prod.env .env
php kode.phar start --host 0.0.0.0 --port 8000 --workers 4

# 或二进制（无需 PHP）
chmod +x kode.bin && ./kode.bin start --host 0.0.0.0 --port 8000 --workers 4
```

工作目录决定：`.env` 从哪读、`storage/` 建在哪、日志写哪。**不是**产物所在目录。

### 5.1 镜像档（C 档）

镜像内**只有单文件 PHAR**，没有源码、没有 vendor、没有 `.env`——部署面与 A 档一致：

```bash
php -d phar.readonly=0 kode pack:phar          # 先出 PHAR
docker build -t kode-app .                      # 项目根 Dockerfile

docker run -d --name kode -p 9527:9527 \
  -e SERVER_HOST=0.0.0.0 -e SERVER_PORT=9527 -e SERVER_WORKERS=2 \
  -e APP_ENV=production -e APP_DEBUG=false \
  -e DB_HOST=postgres -e DB_DATABASE=kode -e DB_USERNAME=kode -e DB_PASSWORD=*** \
  -v kode-storage:/app/storage \
  kode-app

# 覆盖 CMD 跑任意命令
docker run --rm kode-app schedule:list
docker run --rm kode-app status
```

镜像档的五个必知项：

1. **`pdo_pgsql` 必须显式安装**——默认连接是 `pgsql`（`config/database.php` 的
   `env('DB_CONNECTION', 'pgsql')`），而官方 `php:8.3-cli` 镜像**不自带**它，
   需 `libpq-dev` + `docker-php-ext-install pdo_pgsql`。漏装会在首次查询时才炸。
2. **`--host` 必须显式 `0.0.0.0`**——默认值是 `127.0.0.1`（`config/server.php`），
   容器内只听回环则宿主机端口映射无效。
3. **默认端口 `9527`**，可用 `SERVER_PORT` / `--port` 覆盖。
4. **`storage/` 用 volume 挂到 `/app`**——框架以 cwd 为 basePath 创建运行期目录；
   不挂卷则日志/缓存/会话随容器销毁。
5. **OPcache 不会加速 phar**——OPcache 不缓存 phar 内的代码，本档下它零收益；
   Dockerfile 里保留该配置仅为镜像自洽（若改跑源码形态部署才生效）。

> 本机无 docker，`Dockerfile` 与 `.dockerignore` 未经 `docker build` 实测；
> 首次使用请先冒烟：`docker build . && docker run --rm kode-app --help`。

---

## 6. 工程约束（全部经实测，改动前先读）

### 6.1 `phar.readonly` 是 `PHP_INI_SYSTEM`
进程内 `ini_set()` 返回 `false`、值不变。只能用
`php -d phar.readonly=0 kode pack:phar` 启动。引擎检测到只读只报错，**绝不假装能改**。

### 6.2 `mapPhar` 别名必须等于 phar 的 basename
否则运行期致命：`implicit alias "kode.phar" under different alias "kode"`。
因此 `stub()` 必须由别名生成、不能写死；`--name=xxx.phar` 会同时改变别名与桩内容。

### 6.3 `realpath()` 不支持 `phar://` 流（恒返回 `false`）
骨架入口 `kode` 的防递归守卫若用 `realpath() !== realpath()` 会退化成
`false !== false` = `false`，导致打包产物永远找不到框架 CLI。骨架侧已改为 phar 感知。

### 6.4 必须走「暂存目录 + `buildFromDirectory()`」
逐文件 `addFile()` 在 128M `memory_limit` 下、对 44 MB / 6152 文件的 vendor 会被 OOM 杀掉（SIGKILL）。
暂存 + `buildFromDirectory()` 在 512M 下稳定完成。

### 6.5 自动加载表必须**三份一起重写**（本引擎最容易踩的坑）
排除 dev 依赖目录后，composer 映射里指向被排除文件的条目会在 bootstrap 被**硬 `require`** 直接致命
（实测：`myclabs/deep-copy/src/DeepCopy/deep_copy.php`）。关键事实：

> **Composer 2 的真正来源是 `autoload_static.php` 的 `ComposerStaticInit*::$files` / `::$classMap`。**
> `autoload_real.php` 完全不读 `autoload_files.php`——只重写后者等于没改，包内依旧 require myclabs 并致命。

因此必须三份一起重写：`autoload_static.php`（`$files` + `$classMap`）、`autoload_files.php`、
`autoload_classmap.php`。重写规则：

- **按实际文件集过滤**，不硬编码黑名单——逐条 `is_file()`，缺失即剔除（声明⟺实现零漂移落到 autoload 上）；
- 输出路径统一写成 **`__DIR__` 常量表达式**：暂存目录是临时的，绝对路径打进包必然失效；
  `__DIR__` 在包内解析为 `phar://<alias>/vendor/composer`，`'../'` 指回 `vendor/`，`'/../../'` 指回包根；
- **必须是纯常量表达式**：这些值被放进类静态属性，静态属性初始化不允许函数调用
  （`Phar::running(false)` 会报 `Constant expression contains invalid operations`）；
- 片段必须**自带前导斜杠**，否则得到 `vendor/composer../symfony` 这种粘连路径；
- composer 生成的绝对路径先经 `remapToStage()` 重映射回暂存目录，且 `stage()` 内部先 `realpath()`
  ——macOS 的 `sys_get_temp_dir()` 返回未解析的 `/var/...`，而 composer 生成的是已解析的
  `/private/var/...`，两者前缀不匹配会让重映射**静默失效**；
- 块替换用**行级扫描**（找 `public static $prop = array (` 起始行 + `    );` 结束行）：
  非贪婪正则对 4900 行的 `$classMap` 块会 `Recursion limit exhausted`，贪婪正则会越过第一个 `);`
  吞掉后面的块；
- `autoload_psr4.php` / `autoload_namespaces.php` **不重写**：它们用 `__DIR__` 相对路径，
  目录不存在只是查找落空、不会致命。

被剔除的条目会记入构建报告的 `dropped_files`，副作用可审计。

### 6.6 包内存在性检查必须用 `phar://` + 绝对路径
两种失败形态（实测）：

- `is_file('kode.phar/kode')` 恒 `false`——phar 流的 `url_stat` 不认裸 URL 形式；
- `phar://kode.phar/kode` 只按 cwd 解析裸文件名，cwd 不在产物目录时同样 `false`。

正确写法：`file_exists('phar://' . realpath($artifact) . '/kode')`。

### 6.7 Phar API 以实测为准，不照抄手册
本 PHP 构建（8.3.33）下：

- `Phar::getSignature()` 返回 **array**（`hash` / `hash_type`），不是对象——`->getType()` 直接致命；
  引擎对 array / 对象两种形态都兼容；
- `Phar` **无** `isValid()`、`getContents()`、`fileSize()`、`getCurrentFileInfo()`、
  `buildFromDirectory($flags)`；签名真伪由 `new \Phar()` 与遍历时的内部校验兜住（篡改即抛 `UnexpectedValueException`）；
- **别用 `foreach($phar)` 数文件**——迭代器只产出顶层条目（实测 13 项，而实际 4972 个），会严重低估；
  计数用 `$phar->count()`，取桩用 `$phar->getStub()`。

### 6.8 二进制档的平台约束（不可绕过）
二进制 = `php8.x.micro.sfx`（静态 PHP 运行时）+ phar 拼接，格式为
`[custom-ini 头?] + micro.sfx + phar`。

- `micro.sfx` **仅有 x86_64 Linux 构建**——macOS / Windows 一律拒绝；
- 二进制**不支持 Swoole 协程**（静态运行时无协程调度器），依赖协程的功能在二进制档不可用；
- 本机（arm64 macOS）无法产出真实二进制，`pack:bin` 在此只会报平台门禁；
  用 `--sfx=` 显式指定 sfx 可离线绕过门禁（仅用于测试拼接逻辑与格式验证）。

### 6.9 产物内的目录扫描：`glob()` 失效，必须用 `DirectoryIterator`（v1.4.2 实测）

**事故**：把插件发现逻辑下沉到框架后，产物里 `baseDir()` 能正确解析成
`phar://kode.phar/plugins`，但发现函数用的是 `glob()`——
**`glob()` 不支持任何流封装器**，对 `phar://` 路径**静默返回空数组（不是 `false`）**。
结果：产物里「发现 0 个插件」且全程零报错，所有插件无声消失，
`/api/<name>` 全部 404，而 `config/plugins.php` 里明明声明着它们。

| 手段 | 本地目录 | `phar://` | `zip://` |
|---|---|---|---|
| `glob('.../*')` | ✅ | ❌ 返回 `[]`（静默） | ❌ 返回 `[]` |
| `opendir()` / `readdir()` | ✅ | ✅ | ❌ |
| `DirectoryIterator` | ✅ | ✅ | ❌ `UnexpectedValueException: not implemented` |
| `is_dir()` / `is_file()` | ✅ | ✅ | ❌ 返回 `false` |

结论与约束：

1. **扫描产物内目录一律用 `DirectoryIterator`**（走 `SplFileInfo` 流接口，与本地一致）。
   `glob()` 的返回值契约（`false` vs `[]`）在流路径下不可区分，属静默失败高危区。
2. **`DirectoryIterator` 的返回顺序不保证有序**（本地是 inode 序，phar 是写入序）——
   任何依赖「文件顺序」的语义（如同名插件先写者胜）必须**显式 `sort()`**。
3. **`zip://` 不能当插件根目录**——PHP 的 zip 流封装器完全不支持目录遍历
   （`is_dir` / `is_file` / `DirectoryIterator` 全部失败），这是平台限制、不是 bug；
   引擎已做优雅降级：返回空表而非抛错，且不影响切回本地目录后的发现。
4. **`Phar::buildFromDirectory($dir, '/strip')` 的 `$strip` 按正则解释**——
   传 `/plugins` 会抛 `RegexIterator::__construct(): No ending delimiter '/' found`；
   要指定包内路径请用逐条 `addFile($src, 'plugins/X.php')`。

框架侧对应实现：`Kode\Framework\Plugin\PluginDiscoverer`（`listPluginFiles()` 用
`DirectoryIterator` + `sort()`），回归测试 `tests/PluginDiscovererTest.php`
（17 项，含真实 phar 夹具；需 `-d phar.readonly=0` 才会实际执行 phar 用例，
默认 ini 下该用例自动 skip）。

---

## 7. 安全红线

1. **`.env` 永不进包**——含密钥，一律环境变量提供。
2. **`storage/` 永不进包**——运行时数据；且 `phar://` 协议操作上传文件有反序列化风险。
3. **桩必须含 `__HALT_COMPILER()`**——缺失则产物可被继续执行任意后续代码。
4. **签名不可缺**——`verify` 通过 `new \Phar()` 的内部校验兜住篡改（篡改即抛异常）。
5. **声明⟺实现零漂移**——`must_include` 声明的入口必须在包里真的存在，
   缺失即构建失败；autoload 映射同理，指向不存在文件的条目一律剔除。
6. **产物只读**——`pack()` 只读项目源，只写 `build/` 与系统临时目录；暂存目录用完即删。

---

## 8. 校验与排障

### `pack:verify` 校验项

**PHAR 档**（全绿才通过）：

```
exists / bytes / sha256        文件存在、体积、哈希
tier = phar
files                          包内全量条目数（count()，非迭代器）
signature_type                 签名类型（sha256 / openssl）
internal_alias                 包内别名
alias                          文件 basename
has_halt_compiler              桩含 __HALT_COMPILER()
stub_maps_alias                桩 mapPhar 到 basename
has:<must_include 各项>         必备入口链逐一存在（phar:// + 绝对路径）
```

**二进制档**：`exists / bytes / sha256 / tier=binary / has_ini_header / executable_head`
（判定为 magic 或 ELF 头二者居其一）。二进制档**不**要求 `__HALT_COMPILER`、
`mapPhar` 别名或 `must_include`——它不是 phar 容器。

### 常见问题

| 现象 | 原因 | 处理 |
|---|---|---|
| 构建报 `phar.readonly=1` | INI 是系统级 | `php -d phar.readonly=0 kode pack:phar` |
| 构建报 `implicit alias ...` | 产物名与桩别名不一致 | 不改 `packaging.php`，用 `--name=` 时保持 basename 即别名 |
| 部署后 `require ... failed to open stream` | autoload 表指向被排除文件 | 确认三份映射都被重写（§6.5），看 `pack:phar` 报告的 `dropped_files` |
| 包内路径出现 `/private/var/...` | 路径重映射未生效 | 检查 `stage()` 是否传入 realpath 后的目录（§6.5） |
| 验证说缺必备入口，但文件在包里 | 用了裸 phar URL | 统一 `file_exists('phar://' . realpath(...) . '/…')`（§6.6） |
| 文件数显示 13 | 用了 `foreach($phar)` 计数 | 用 `$phar->count()`（§6.7） |
| `pack:bin` 报平台不支持 | 非 x86_64 Linux | 换 x86_64 Linux 构建，或接受 PHAR 档 |
| 二进制跑不起来 | 目标机非 x86_64 Linux / glibc 太老 | micro.sfx 是静态 x86_64 Linux 运行时 |

---

## 9. 自测

```bash
./vendor/bin/phpunit tests/AppPackagerTest.php    # 45 项，覆盖引擎全部分支
./vendor/bin/phpunit                              # 全量套件
```

测试不触碰项目源、不写 `build/`：全部临时数据落在 `sys_get_temp_dir()` 下并在 `tearDown` 递归删除；
`AppPackager::override()` 是进程级静态状态，每个用例后强制归位。
完整 `pack()` 构建路径（需 `phar.readonly=0` 且产出 25 MB 产物）与 `downloadSfx()`（外部网络 IO）
不在单元测试内，由命令行真机验证。
