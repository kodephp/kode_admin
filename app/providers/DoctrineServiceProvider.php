<?php

declare(strict_types=1);

namespace app\providers;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Kode\Framework\Providers\ServiceProvider;

/**
 * Doctrine ORM 接线（项目默认 ORM，pgsql）。
 *
 * EntityManager 以单例进容器：`resolve(EntityManagerInterface::class)`。
 * 常驻进程下每请求由 DoctrineClearMiddleware::clear() 防止 UnitOfWork 堆积。
 */
final class DoctrineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // timestamptz 宽容解析（pgsql NOW() 带微秒，DBAL 内建类型严格格式必炸）。
        if (!\Doctrine\DBAL\Types\Type::hasType('timestamptz_var')) {
            \Doctrine\DBAL\Types\Type::addType('timestamptz_var', \app\doctrine\VarTimestamptzType::class);
        }

        $this->container->singleton(EntityManagerInterface::class, function (): EntityManagerInterface {
            /** @var array<string, mixed> $cfg */
            $cfg = (array) $this->config('doctrine', []);

            $paths = [];
            foreach ((array) ($cfg['paths'] ?? ['app/models']) as $rel) {
                $base = (string) $this->config('path.base', '');
                $paths[] = $base !== '' ? $base . '/' . ltrim((string) $rel, '/') : (string) $rel;
            }

            $dev = (bool) ($cfg['dev'] ?? false);
            $ormConfig = ORMSetup::createAttributeMetadataConfiguration($paths, $dev);

            $proxyDir = (string) ($cfg['proxy_dir'] ?? '');
            if ($proxyDir === '') {
                $proxyDir = rtrim((string) $this->config('path.base', ''), '/') . '/storage/doctrine/proxies';
            }
            if (!is_dir($proxyDir)) {
                @mkdir($proxyDir, 0775, true);
            }
            $ormConfig->setProxyDir($proxyDir);
            $ormConfig->setAutoGenerateProxyClasses($dev);

            /** @var array<string, mixed> $conn */
            $conn = (array) ($cfg['connection'] ?? []);
            $connection = DriverManager::getConnection($conn);

            return new EntityManager($connection, $ormConfig);
        });

        $this->container->alias('doctrine.em', EntityManagerInterface::class);
    }
}
