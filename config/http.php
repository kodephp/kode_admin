<?php

/*
 * HTTP 内核配置（请求处理、输入健壮性与错误响应）
 *
 * 这里只放框架 HTTP 层自身的开关，路由/CORS/安全头/限流等另有独立配置文件。
 *
 * 错误响应（原 config/response.php 合并于此）：
 *   - 统一交由 kode/exception 的 ExceptionManager + UnifiedResponseFormatter 完成；
 *   - 默认即结构化 JSON，开发环境（app.debug=true）含 file / line / chain，
 *     可直接追踪到出错的源文件与行号；生产环境（app.debug=false）自动收敛
 *     绝对路径与系统异常细节（统一 message）。
 */

return [
    /*
     * 请求体 JSON 严格校验。
     * 开启后，Content-Type 为 application/json（或 +json）且 body 非空的请求，
     * 若 body 不是合法 JSON，直接返回 400（而非静默当作空对象、下游以 422/500 收场）。
     * 默认关闭，避免影响以表单/纯文本为 body 的既有接口。
     */
    'json_strict' => (bool) env('HTTP_JSON_STRICT', false),

    // 跳过 JSON 严格校验的路径前缀（探针、指标等无需 body 校验）。
    'json_skip_paths' => ['/health', '/metrics', '/ping'],

    /*
     * 框架安全层开关（默认开；同时关闭即裸模式，仅压测用，见 benchmarks §七）。
     */
    'exception_middleware' => (bool) env('HTTP_EXCEPTION_MIDDLEWARE', true),
    'connection_cleanup' => (bool) env('HTTP_CONNECTION_CLEANUP', true),

    /*
     * HTTP 错误响应配置（非信封模式，由 Resp::error 使用）。
     */
    'error_keys' => [
        'message' => 'message',
        'errors'  => 'errors',
    ],

    /*
     * kode/exception 生产模式收敛后的对外提示语（production 下系统异常不泄露内部细节）。
     * 如需自定义覆盖，可在此设置后由 ExceptionServiceProvider 注入格式化器。
     */
    'production_message' => env('HTTP_PRODUCTION_MESSAGE', '系统繁忙，请稍后重试'),
];