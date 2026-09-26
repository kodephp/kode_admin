# 插件市场协议（官网 ↔ 通用后台对接规范 v1）

> 目标：公司官网展示插件，后台开发者一键安装。本文定义两边必须遵守的契约。

## 1. 官网侧（市场服务）须提供的接口

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | /api/market/plugins?keyword=&page= | 插件列表：`{code,msg,dur,data:[{name,version,description,author,menus,tables,sha256,download_url}],total,page,size}` |
| GET | /api/market/plugins/{name} | 详情（含更新日志、依赖说明） |
| GET | /files/{name}-{version}.zip | 安装包下载（sha256 与列表一致） |

## 2. 安装包格式（zip）

- 根级 `<Name>Plugin.php`（实现 `PluginInterface`，`name()` 恒等于 URL 前缀与目录名）；
- `<name>/*.php`（控制器等，命名空间 `Kode\Plugins\<name>\...`）；
- `plugin.json` 清单：`{name, version, tables, menus, kode, contract}`，
  `contract` 由 `app\support\PluginContract::describe()` 产出，
  市场侧据此审阅能力契约，**无需加载插件代码**。

### 2.1 条目白名单（后台解包时逐条判定，`PluginPackager::packageEntry()`）

| 条目 | 判定 | 理由 |
| --- | --- | --- |
| `plugin.json` | 有意跳过（不落盘） | 清单在落盘前由 `readManifest()` 消费完即弃，留在 `plugins/` 根下只会被扫描路径误认 |
| 根级 `XxxPlugin.php` | 收 | 主类，位置约定在根 |
| `<name>/任意层级/*.php` | 收 | 控制器/support 等，命名空间 `Kode\Plugins\<name>\...` |
| 非 `.php` 条目（`.so` `.sh` 二进制…） | **抛错，整包中止** | 市场协议只传代码，别的扩展名不可审阅 |
| 点开头条目（`.env.php` `.htaccess.php`）、含 `.`/`..` 段 | **抛错，整包中止** | 落在 web 可达目录就是配置注入；`..` 段同时是越界 |
| 根级其它 `.php`、`<别的名>/x.php` | **抛错，整包中止** | 落点不在约定两处 = 借市场包往框架目录写文件 |
| 与 `plugins/` 已有文件同名 | **抛错，整包中止** | 不覆盖：覆盖别人的插件文件等于一次无告警的升级 |

违规条目一律抛错并回滚本次已落盘的文件与新建目录，**不做逐条目 `continue`**——
静默跳过会把「半装」当成功返回，状态照常翻成启用，缺文件要到运行时才炸。
（`SafeArchive` 与 `PluginPackager` 各有独立的越界判定，两层不共享实现。）

## 3. 后台侧（本系统已实现）

- 打包：`GET /api/plugins/{name}/package`（admin）；
- 安装：`POST /api/plugins/install-remote {url, sha256, name}` ——
  闸门顺序固定为：**入参格式 → 出网闸 → sha256 → 清单 → 解包**，任一环节失败即返回
  `{code:400,msg,dur}`（`$this->fail()`，不是 200 里的 `{ok:false}`），且不改动 `plugins/` 一个字节、不登记状态；
- 生命周期：`install/uninstall/pause/resume/upgrade`（见 README-ADMIN），
  卸载默认留数据 + pg_dump 备份命令（命令只带表名，主机/账号/库名不出服务端），`purge=1` 删表需 `confirm=1`。

### 3.1 出网闸（`app\support\RemoteFetch`，市场 / GiteeRelease / 工作流 webhook 共用同一口径）

| 环节 | 规则 |
| --- | --- |
| 协议 | 只允许 http(s)；`file://` `gopher://` `phar://` `php://` 等一律拒（scheme 先于 host 判，否则 `file:///etc/passwd` 的空 host 会把原因误报成「地址无法解析」） |
| 凭证 | URL 带 `user:pass@` 直接拒（会带进 Basic Auth，也可能被日志原样记下） |
| 内网 | 解析出的**每一条** A/AAAA 都要过 `NO_PRIV_RANGE\|NO_RES_RANGE`，只看第一条等于给「公网+内网轮询」域名留门；本地联调自建市场靠 `MARKET_ALLOW_PRIVATE=true` 放行 |
| 重定向 | 跳转由本类自己跟（curl `FOLLOWLOCATION=false`），**每一跳重新过闸**，最多 3 跳；协议相对地址 `//host/x` 继承当前 scheme，带 scheme 的 Location 原样交给闸门判定，绝不拼进当前主机 |
| DNS 重绑定 | 过闸后用 `CURLOPT_RESOLVE` 把**已校验的那个 IP**钉住再连，杜绝「查一次、连时再查一次」的 TOCTOU；主机本身就是 IP 字面量时不拼（没有二次解析可钉，且 spec 以 `:` 分段，IPv6 地址拼进去只会得到一条歧义映射） |
| 体积 | 20MB 上限**边下边算**（progress 回调看已落盘字节，超预算立刻断线），不是下完再判；失败路径一律 `unlink`，不留半截产物 |
| TLS | `SSL_VERIFYPEER=true` + `VERIFYHOST=2` |

三个入口，按调用方要的东西分：**`vet()`** 纯规则（只想知道行不行）；**`toFile()`** 把产物抓到文件
（市场包、GiteeRelease 资产包）；**`safeOpts()`** 给「自己还要拿 curl 发 GET/POST」的调用方
（webhook 投递、工作流 `api_call` 节点、告警通知）——返回一份可直接 `curl_setopt_array` 合并的
协议白名单 + 钉 IP + TLS 选项包，拒收时**不给 `opts` 键**（少了这个约定，调用方合并的就是 `null`）。

`safeOpts()` 存在的原因：闸门只写在 `toFile()` 里，等于管不住另起炉灶的 `curl_init($用户URL)`。
实测 `file:///etc/passwd` 配 `CURLOPT_RETURNTRANSFER` 会被 curl 自己解释并把文件内容读进响应体
（工作流 `api_call` 节点的响应正好回给调用方，`.env` 就这么被带走）；
而 `CURLOPT_NOPROXY` 只决定「哪些主机不经代理」，对直连 `169.254.169.254` 一点作用都没有 ——
内网拒绝必须由 `vet()` 做。两条接线红线：
1. 闸门选项放合并表达式的**左侧**（`$guarded['opts'] + $业务opts`），同名的协议/TLS/钉 IP 项由闸门说了算；
2. 目标地址若含运行时变量（`http://{{host}}/x`），闸门必须在**变量替换之后**，否则查的是一个地址、连的是另一个。

`safeOpts()` 交的是「一次性」判定：它只保证起始地址过了闸，跳转逐跳复验是 `toFile()` 那套自己跟跳转的
循环才有的能力。所以这三个调用点**都不开 `CURLOPT_FOLLOWLOCATION`**（curl 默认 false），3xx 就只是一个
3xx 响应。日后谁把它翻开，`REDIR_PROTOCOLS` 已经先把协议越界那条路堵死（不会跳去 `file://`），
但「跳到内网」就没有第二次判定了 —— 翻开之前要先换成逐跳复验。

断言见 `tests/WorkflowOutboundGateTest.php`（含「每个 `curl_init` 配一处 `safeOpts`」的静态门禁）。

## 4. 安全红线（两边共守）

- 校验失败的包绝不落盘、不执行其中任何代码；
- 市场服务须 HTTPS；后台限制下载来源为 http(s)（逐跳过闸，见 §3.1）；
- 清单必须自述身份：`name` 与请求安装的名字不一致视为偷名，整包拒；
  声明了 `kode` 下限就必须满足，否则 `>=2.0` 的包能装到 1.3 上运行；
- 缺 `plugin.json` 或清单里一个 `.php` 都没有的「空壳包」不得被翻成启用状态；
- 插件代码以发布者签名为准（后续版本：sig 字段 + 官方公钥验签）。
