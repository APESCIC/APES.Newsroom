<?php

namespace App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/**
 * Bridges Cloudron's addon environment variables into Laravel's own
 * configuration keys at boot time.
 *
 * Cloudron injects credentials for provisioned addons (MySQL, Redis,
 * outbound mail) as CLOUDRON_*-prefixed environment variables, and those
 * values are rotated by the platform - they must be read at runtime, never
 * cached or committed (see docs.cloudron.io/packaging/addons). Laravel's
 * own config files expect the conventional DB_, REDIS_, and MAIL_ names.
 * This provider translates one into the other so the same config files
 * and .env-driven local/CI setup work unchanged in both environments:
 * when a CLOUDRON_* variable is absent (local dev, CI), nothing is
 * overridden and the standard .env values apply.
 *
 * This must run before anything resolves a database, cache, queue, or
 * mail connection - register() runs before those are ever lazily
 * connected, and this provider is listed first in bootstrap/providers.php.
 */
class CloudronEnvironmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mapMysql();
        $this->mapRedis();
        $this->mapMail();
        $this->mapAppOrigin();
        $this->mapOidc();
        $this->mapLdap();
    }

    /**
     * Read Cloudron-injected environment variables at runtime.
     *
     * Must use getenv(), not env(), so mapping still works after
     * `config:cache` (Laravel disables env() outside config files when
     * configuration is cached).
     */
    private function cloudronEnv(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        return $value;
    }

    private function mapMysql(): void
    {
        if (! $this->cloudronEnv('CLOUDRON_MYSQL_HOST')) {
            return;
        }

        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.host', $this->cloudronEnv('CLOUDRON_MYSQL_HOST'));
        Config::set('database.connections.mysql.port', $this->cloudronEnv('CLOUDRON_MYSQL_PORT', '3306'));
        Config::set('database.connections.mysql.database', $this->cloudronEnv('CLOUDRON_MYSQL_DATABASE'));
        Config::set('database.connections.mysql.username', $this->cloudronEnv('CLOUDRON_MYSQL_USERNAME'));
        Config::set('database.connections.mysql.password', $this->cloudronEnv('CLOUDRON_MYSQL_PASSWORD'));
    }

    private function mapRedis(): void
    {
        if (! $this->cloudronEnv('CLOUDRON_REDIS_HOST')) {
            return;
        }

        foreach (['default', 'cache'] as $connection) {
            Config::set("database.redis.{$connection}.host", $this->cloudronEnv('CLOUDRON_REDIS_HOST'));
            Config::set("database.redis.{$connection}.port", $this->cloudronEnv('CLOUDRON_REDIS_PORT', '6379'));
            Config::set("database.redis.{$connection}.password", $this->cloudronEnv('CLOUDRON_REDIS_PASSWORD'));
        }

        Config::set('cache.default', 'redis');
        Config::set('queue.default', 'redis');
        Config::set('session.driver', 'redis');
    }

    private function mapMail(): void
    {
        if (! $this->cloudronEnv('CLOUDRON_MAIL_SMTP_SERVER')) {
            return;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $this->cloudronEnv('CLOUDRON_MAIL_SMTP_SERVER'));
        Config::set('mail.mailers.smtp.port', $this->cloudronEnv('CLOUDRON_MAIL_SMTP_PORT'));
        Config::set('mail.mailers.smtp.username', $this->cloudronEnv('CLOUDRON_MAIL_SMTP_USERNAME'));
        Config::set('mail.mailers.smtp.password', $this->cloudronEnv('CLOUDRON_MAIL_SMTP_PASSWORD'));

        if ($this->cloudronEnv('CLOUDRON_MAIL_FROM')) {
            Config::set('mail.from.address', $this->cloudronEnv('CLOUDRON_MAIL_FROM'));
        }

        if ($this->cloudronEnv('CLOUDRON_MAIL_FROM_DISPLAY_NAME')) {
            Config::set('mail.from.name', $this->cloudronEnv('CLOUDRON_MAIL_FROM_DISPLAY_NAME'));
        }
    }

    private function mapAppOrigin(): void
    {
        // CLOUDRON_APP_ORIGIN is the canonical https origin Cloudron has
        // provisioned for this app. Prefer it over a hand-maintained
        // APP_URL so redirects, signed links, and asset URLs stay correct
        // if the app is ever moved between beta and production domains.
        $origin = $this->cloudronEnv('CLOUDRON_APP_ORIGIN');

        if ($origin) {
            Config::set('app.url', $origin);
        }

        // Cloudron always serves apps over HTTPS, so session cookies must
        // never be sent over plain HTTP regardless of the shared .env.
        if (is_string($origin) && str_starts_with($origin, 'https://')) {
            Config::set('session.secure', true);
        }
    }

    private function mapOidc(): void
    {
        if (! $this->cloudronEnv('CLOUDRON_OIDC_DISCOVERY_URL')) {
            return;
        }

        Config::set('services.cloudron_oidc.discovery_url', $this->cloudronEnv('CLOUDRON_OIDC_DISCOVERY_URL'));
        Config::set('services.cloudron_oidc.issuer', $this->cloudronEnv('CLOUDRON_OIDC_ISSUER'));
        Config::set('services.cloudron_oidc.client_id', $this->cloudronEnv('CLOUDRON_OIDC_CLIENT_ID'));
        Config::set('services.cloudron_oidc.client_secret', $this->cloudronEnv('CLOUDRON_OIDC_CLIENT_SECRET'));

        if ($providerName = $this->cloudronEnv('CLOUDRON_OIDC_PROVIDER_NAME')) {
            Config::set('services.cloudron_oidc.provider_name', $providerName);
        }
    }

    private function mapLdap(): void
    {
        if (! $this->cloudronEnv('CLOUDRON_LDAP_URL')) {
            return;
        }

        $ldapUrl = (string) $this->cloudronEnv('CLOUDRON_LDAP_URL');
        $parsed = parse_url($ldapUrl);
        $host = $this->cloudronEnv('CLOUDRON_LDAP_HOST')
            ?: ($parsed['host'] ?? null);
        $port = $this->cloudronEnv('CLOUDRON_LDAP_PORT')
            ?: ($parsed['port'] ?? 389);

        if ($host) {
            Config::set('ldap.connections.default.hosts', [(string) $host]);
        }

        Config::set('ldap.connections.default.port', (int) $port);
        Config::set('ldap.connections.default.username', $this->cloudronEnv('CLOUDRON_LDAP_BIND_DN'));
        Config::set('ldap.connections.default.password', $this->cloudronEnv('CLOUDRON_LDAP_BIND_PASSWORD'));
        Config::set('ldap.connections.default.base_dn', $this->cloudronEnv('CLOUDRON_LDAP_USERS_BASE_DN'));
        Config::set('services.cloudron_ldap.url', $ldapUrl);
        Config::set('services.cloudron_ldap.users_base_dn', $this->cloudronEnv('CLOUDRON_LDAP_USERS_BASE_DN'));
        Config::set('services.cloudron_ldap.groups_base_dn', $this->cloudronEnv('CLOUDRON_LDAP_GROUPS_BASE_DN'));
        Config::set('services.cloudron_ldap.bind_dn', $this->cloudronEnv('CLOUDRON_LDAP_BIND_DN'));
        Config::set('services.cloudron_ldap.bind_password', $this->cloudronEnv('CLOUDRON_LDAP_BIND_PASSWORD'));
    }
}
