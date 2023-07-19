<?php
/**
 * Provides informations about WordPress tables.
 *
 * @package lucatume\WPBrowser\Generators
 */

namespace lucatume\WPBrowser\Generators;

use Codeception\Lib\Driver\Db;
use lucatume\WPBrowser\Exceptions\InvalidArgumentException;
use lucatume\WPBrowser\Utils\Strings;
use RuntimeException;
use Codeception\Lib\Driver\Sqlite;
use Codeception\Lib\Driver\MySql;

/**
 * Class Tables
 *
 * @package lucatume\WPBrowser\Generators
 */
class Tables
{
    /**
     * The absolute path to the templates directory.
     */
    private string $templatesDir;

    /**
     * Tables constructor.
     */
    public function __construct(string $driverClass)
    {
        $dir = match ($driverClass) {
            Sqlite::class => 'sqlite',
            MySql::class => 'mysql',
            default => throw new InvalidArgumentException('Unsupported database type ' . $driverClass)
        };
        $this->templatesDir = __DIR__ . '/' . $dir;
    }

    /**
     * Returns a list of default table names for a single site WordPress installation.
     *
     * @param string $table_prefix The table prefix to prepend to each blog table name.
     * @param int $blog_id         The ID of the blog to return the table names for.
     *
     * @return array<string> The list of tables, not prefixed with the table prefix.
     */
    public static function blogTables(string $table_prefix = '', int $blog_id = 1): array
    {
        $blog_id = (int)$blog_id < 2 ? '' : $blog_id . '_';
        return array_map(static function ($table) use ($table_prefix, $blog_id): string {
            return sprintf('%s%s%s', $table_prefix, $blog_id, $table);
        }, [
            'commentmeta',
            'comments',
            'links',
            'options',
            'postmeta',
            'posts',
            'term_relationships',
            'term_taxonomy',
            'termmeta',
            'terms'
        ]);
    }

    /**
     * Returns the SQL query to perform a table operation.
     *
     * @param string $table The table name or table operation slug.
     *
     * @return string The SQL query.
     *
     * @throws RuntimeException If the SQL query cannot be fetched.
     */
    private function templates(string $table): string
    {
        $templateFile = $this->templatesDir . DIRECTORY_SEPARATOR . "$table.handlebars";

        if (!is_file($templateFile)) {
            throw new RuntimeException("Template file {$templateFile} not found.");
        }

        $queryTemplate = file_get_contents($templateFile);

        if (false === $queryTemplate) {
            throw new RuntimeException("Template file {$templateFile} could not be read.");
        }

        return $queryTemplate;
    }

    /**
     * Returns the SQL code required to scaffold a blog tables.
     *
     * @param string $prefix            The blog prefix.
     * @param int $blogId               The blog ID.
     * @param array<string,mixed> $data The blog data.
     *
     * @return string The SQL query.
     */
    public function getBlogScaffoldQuery(string $prefix, int $blogId, array $data): string
    {
        $template = $this->templates('new-blog');
        $data = array_merge([
            'subdomain' => '',
            'domain' => '',
            'subfolder' => '',
            'stylesheet' => '',
            'prefix' => $prefix,
            'blog_id' => $blogId,
            'scheme' => 'http'
        ], $data);

        $data['siteurl'] = $data['siteurl'] ?? sprintf(
            '%s://%s%s%s',
            isset($data['scheme']) && is_string($data['scheme']) ? $data['scheme'] : 'http',
            $data['subdomain'] ? $data['subdomain'] . '.' : '',
            isset($data['domain']) && is_string($data['domain']) ? $data['domain'] : '',
            $data['subfolder'] ? '/' . $data['subfolder'] : ''
        );
        $data['home'] = $data['home'] ?? $data['siteurl'];
        $data['template'] = $data['template'] ?? $data['stylesheet'];

        return Strings::renderString($template, $data);
    }

    /**
     * Returns the SQL code to drop a blog tables.
     *
     * @param string $tablePrefix The database table prefix.
     * @param int $blogId         The blog ID.
     *
     * @return string SQL code.
     */
    public function getBlogDropQuery(string $tablePrefix, int $blogId): string
    {
        $template = $this->templates('drop-blog-tables');
        $data = [
            'prefix' => $tablePrefix,
            'blog_id' => $blogId
        ];

        return Strings::renderString($template, $data);
    }
}
