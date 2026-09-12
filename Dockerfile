# syntax=docker/dockerfile:1.6
# Kode 通用后台 —— 镜像档（三档形态中的 C 档；详见 docs/PACKAGING.md §1）
#
# 三档对比：
#   A. PHAR    build/kode.phar   目标机自备 PHP        任意平台
#   B. 二进制  build/kode.bin    无需 PHP              仅 x86_64 Linux
#   C. 镜像    本 Dockerfile     PHP 在镜像内          任意 Linux
#
# 构建（PHAR 在本机出，不在镜像里装 composer、不跑依赖解析）：
#   php -d phar.readonly=0 kode pack:phar
#   docker build -t kode-app:1.1.0 .
#
# 运行：
#   docker run -d --name kode -p 9527:9527 \
#     -e SERVER_HOST=0.0.0.0 -e SERVER_PORT=9527 -e SERVER_WORKERS=2 \
#     -e APP_ENV=production -e APP_DEBUG=false \
#     -e DB_HOST=postgres -e DB_DATABASE=kode -e DB_USERNAME=kode -e DB_PASSWORD=*** \
#     -v kode-storage:/app/storage \
#     kode-app:1.1.0
#
# 覆盖 CMD 跑任意命令：
#   docker run --rm kode-app:1.1.0 schedule:list
#   docker run --rm kode-app:1.1.0 status
#
# 设计要点：
#   - 单阶段：镜像内只有单文件 PHAR，无源码、无 vendor、无 .env —— 部署面与 A 档一致，
#     密钥一律走 env（12-factor）。
#   - 入口是 `php /app/kode.phar`，命令名直接给（start / stop / status / schedule:list …）。
#     历史版本这里写过 `bin/kode serve`——该路径与命令在本骨架均不存在，已修正。
#   - 默认 DB 连接是 pgsql（见 config/database.php 的 env('DB_CONNECTION', 'pgsql')），
#     而官方 php:8.3-cli 镜像不自带 pdo_pgsql，必须显式安装（需 libpq-dev）。
#     sqlite 驱动同时保留，可用于本地零配置冒烟。
#   - phar.readonly 只限制「写」phar，运行 phar 不需要改它。
#   - storage/ 不进包（运行时数据），用 volume 挂到工作目录 /app 下：
#     框架以 cwd 为 basePath 解析配置与创建 storage。
#   - 非 root 用户 app（uid 1000）运行。
#   - 默认 host/port 来自 config/server.php（127.0.0.1:9527），容器内必须显式
#     --host 0.0.0.0，否则只能被容器自身访问。
#
# 注意：本机无 docker，本文件未经 docker build 实测；首次使用请先冒烟一次
#       `docker build . && docker run --rm kode-app:1.1.0 --help`。
# ─────────────────────────────────────────────────────────────
FROM php:8.3-cli-bookworm

LABEL org.opencontainers.image.title="kode-app" \
      org.opencontainers.image.description="Kode 通用后台（PHAR 单文件镜像档）"

# 运行期扩展：pdo_pgsql（默认连接）、zip、opcache。
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libzip-dev \
 && docker-php-ext-install pdo_pgsql zip opcache \
 && rm -rf /var/lib/apt/lists/*

# opcache 生产配置。
# 注意：OPcache 不缓存 phar 内的代码，本档下它不会带来性能收益；
# 保留配置仅为镜像自洽（若改用源码形态部署则生效）。
COPY <<'INI' /usr/local/etc/php/conf.d/kode.ini
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
memory_limit=1024M
date.timezone=Asia/Shanghai
INI

WORKDIR /app

# 运行时用户（非 root）+ 运行期目录归属。
RUN useradd -m -u 1000 app \
 && mkdir -p /app/storage/logs /app/storage/cache /app/storage/runtime /app/storage/uploads \
 && chown -R app:app /app /home/app

# 单文件产物。build/ 与 .env 都不在仓库里，也不进包。
COPY --chown=app:app build/kode.phar /app/kode.phar

ENV TZ=Asia/Shanghai \
    APP_ENV=production \
    APP_DEBUG=false \
    SERVER_HOST=0.0.0.0 \
    SERVER_PORT=9527

USER app

EXPOSE 9527

# 存活探针：/health/live 是最小探针（不依赖 DB 等外部组件）。
# 组件级就绪语义请由编排器另行打 /health/ready（不健康时返回 503）。
HEALTHCHECK --interval=10s --timeout=2s --retries=3 --start-period=10s \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:9527/health/live") ? 0 : 1);'

ENTRYPOINT ["php", "/app/kode.phar"]
CMD ["start", "--host", "0.0.0.0", "--port", "9527", "--workers", "2"]
