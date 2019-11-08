<?php
/**
 * Miscellaneous utility functions for the wp-browser library.
 *
 * @package tad\WPBrowser
 */

namespace tad\WPBrowser;

/**
 * Builds an array format command line, compatible with the Symfony Process component, from a string command line.
 *
 * @param string|array $command The command line to parse, if in array format it will not be modified.
 *
 * @return array The parsed command line, in array format. Untouched if originally already an array.
 *
 * @uses \Symfony\Component\Process\Process To parse and escape the command line.
 */
function buildCommandline($command)
{
    if (empty($command) || is_array($command)) {
        return array_filter((array)$command);
    }

    $escapedCommandLine = (new \Symfony\Component\Process\Process($command))->getCommandLine();
    $commandLineFrags   = explode(' ', $escapedCommandLine);

    if (count($commandLineFrags) === 1) {
        return $commandLineFrags;
    }

    $open                   = false;
    $unescapedQuotesPattern = '/(?<!\\\\)("|\')/u';

    return array_reduce($commandLineFrags, static function (array $acc, $v) use (&$open, $unescapedQuotesPattern) {
        $containsUnescapedQuotes = preg_match_all($unescapedQuotesPattern, $v);
        $v                       = $open ? array_pop($acc) . ' ' . $v : $v;
        $open                    = $containsUnescapedQuotes ?
            $containsUnescapedQuotes & 1 && (bool)$containsUnescapedQuotes !== $open
            : $open;
        $acc[]                   = preg_replace($unescapedQuotesPattern, '', $v);

        return $acc;
    }, []);
}

/**
 * Create the slug version of a string.
 *
 * This will also convert `camelCase` to `camel-case`.
 *
 * @param string $string The string to create a slug for.
 * @param string $sep    The separator character to use, defaults to `-`.
 * @param bool   $let    Whether to let other common separators be or not.
 *
 * @return string The slug version of the string.
 */
function slug($string, $sep = '-', $let = false)
{
    $unquotedSeps = $let ? ['-', '_', $sep] : [$sep];
    $seps         = implode('', array_map(static function ($s) {
        return preg_quote($s, '~');
    }, array_unique($unquotedSeps)));

    // Prepend the separator to the first uppercase letter and trim the string.
    $string = preg_replace('/(?<![A-Z' . $seps . '])([A-Z])/u', $sep . '$1', trim($string));

    // Replace non letter or digits with the separator.
    $string = preg_replace('~[^\pL\d' . $seps . ']+~u', $sep, $string);

    // Transliterate.
    $string = iconv('utf-8', 'us-ascii//TRANSLIT', $string);

    // Remove anything that is not a word or a number or the separator(s).
    $string = preg_replace('~[^' . $seps . '\w]+~', '', $string);

    // Trim excess separator chars.
    $string = trim(trim($string), $seps);

    // Remove duplicate separators and lowercase.
    $string = strtolower(preg_replace('~[' . $seps . ']{2,}~', $sep, $string));

    // Empty strings are fine here.
    return $string;
}

function renderString($template, array $data = [], array $fnArgs = [])
{
    $fnArgs = array_values($fnArgs);

    $replace = array_map(
        static function ($value) use ($fnArgs) {
            return is_callable($value) ? $value(...$fnArgs) : $value;
        },
        $data
    );

    if (false !== strpos($template, '{{#')) {
        /** @var \Closure $compiler */
        $compiler = \LightnCandy\LightnCandy::prepare(\LightnCandy\LightnCandy::compile($template));

        return $compiler($replace);
    }

    $search = array_map(
        static function ($k) {
            return '{{' . $k . '}}';
        },
        array_keys($data)
    );

    return str_replace($search, $replace, $template);
}

/**
 * Ensures a condition else throws an invalid argument exception.
 *
 * @param bool   $condition The condition to assert.
 * @param string $message   The exception message.
 */
function ensure($condition, $message)
{
    if ($condition) {
        return;
    }
    throw new \InvalidArgumentException($message);
}

/**
 * A safe wrapper around the `parse_url` function to ensure consistent return format.
 *
 * Differently from the internal implementation this one does not accept a component argument.
 *
 * @param string $url The input URL.
 *
 * @return array An array of parsed components, or an array of default values.
 */
function parseUrl($url)
{
    return \parse_url($url) ?: [
        'scheme'   => '',
        'host'     => '',
        'port'     => 0,
        'user'     => '',
        'pass'     => '',
        'path'     => '',
        'query'    => '',
        'fragment' => ''
    ];
}

/**
 * Builds a \DateTimeImmutable object from another object, timestamp or `strtotime` parsable string.
 *
 * @param mixed $date A dates object, timestamp or `strtotime` parsable string.
 *
 * @return \DateTimeImmutable The built date or `now` date if the date is not parsable by the `strtotime` function.
 * @throws \Exception If the `$date` is a string not parsable by the `strtotime` function.
 */
function buildDate($date)
{
    if ($date instanceof \DateTimeImmutable) {
        return $date;
    }
    if ($date instanceof \DateTime) {
        return \DateTimeImmutable::createFromMutable($date);
    }

    return new \DateTimeImmutable(is_numeric($date) ? '@' . $date : $date);
}

/**
 * Finds a parent directory that passes a check.
 *
 * @param string   $dir   The path to the directory to check.
 * @param callable $check The check to run on the directory.
 *
 * @return bool|string The directory path, or `false` if not found.
 */
function findParentDirThat($dir, callable $check)
{
    do {
        if ($check($dir)) {
            return $dir;
        }

        $parent = dirname($dir);

        if ($dir === $parent) {
            return false;
        }

        $dir = $parent;
    } while ($dir);

    return false;
}

/**
 * Finds a directory, child to the current one, that passes a check.
 *
 * @param string   $dir   The path to the directory to check.
 * @param callable $check The check to run on the directory.
 *
 * @return bool|string The directory path, or `false` if not found.
 */
function findChildDirThat($dir, callable $check)
{
    $found = $check($dir);

    if ($found) {
        return $dir;
    }

    $dirs = new \CallbackFilterIterator(
        new \FilesystemIterator(
            $dir,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS | \FilesystemIterator::CURRENT_AS_PATHNAME
        ),
        static function ($f) {
            return is_dir($f);
        }
    );

    foreach ($dirs as $childDir) {
        if ($found = findChildDirThat($childDir, $check)) {
            return $found;
        }
    }

    return false;
}

/**
 * Finds the WordPress root directory in a parent or child directory.
 *
 * @param string|null $startDir The path to the directory to check, or `null` to use the current working directory.
 * @param string|null $default The default value to return, or `null` to use the current working directory.
 *
 * @return string The path to the WordPress root folder, if found, or the default value.
 */
function findWordPressRootDir($startDir = null, $default = null)
{
    $getcwd   = getcwd();

    if ($getcwd === false) {
        return $default;
    }

    $startDir = $startDir ?: (string)$getcwd;
    $default  = $default ?: (string)$getcwd;

    $dir = $startDir;

    $isWpRoot = static function ($dir) {
        return file_exists($dir . '/wp-load.php');
    };

    $match = findParentDirThat($dir, $isWpRoot);

    if (! $match) {
        $match = findChildDirThat($dir, $isWpRoot);
    }

    return $match ?: $default;
}
