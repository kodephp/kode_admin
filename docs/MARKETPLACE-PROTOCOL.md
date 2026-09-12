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

## 3. 后台侧（本系统已实现）

- 打包：`GET /api/plugins/{name}/package`（admin）；
- 安装：`POST /api/plugins/install-remote {url, sha256, name}` ——
  仅 http(s)、20MB 上限、sha256 强制校验、解包拒绝 `..`/绝对路径/非 php、
  已存在文件不覆盖、落盘 + 登记后提示 reload 生效；
- 生命周期：`install/uninstall/pause/resume/upgrade`（见 README-ADMIN），
  卸载默认留数据 + pg_dump 备份命令，`purge=1` 删表需 `confirm=1`。

## 4. 安全红线（两边共守）

- 校验失败的包绝不落盘、不执行其中任何代码；
- 市场服务须 HTTPS；后台限制下载来源为 http(s)；
- 插件代码以发布者签名为准（后续版本：sig 字段 + 官方公钥验签）。
