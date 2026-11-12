<?php

namespace App\Support\Meta;

/**
 * Appends UTM params without corrupting existing query strings or fragments.
 */
final class MetaUtm
{
    /**
     * @param  array<string, string>  $params
     */
    public static function append(string $url, array $params): string
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        foreach ($params as $key => $value) {
            if ($key === '' || $value === '') {
                continue;
            }
            // Do not overwrite caller-supplied UTMs.
            if (! array_key_exists($key, $query)) {
                $query[$key] = $value;
            }
        }

        $rebuilt = $parts['scheme'].'://';
        if (! empty($parts['user'])) {
            $rebuilt .= $parts['user'];
            if (! empty($parts['pass'])) {
                $rebuilt .= ':'.$parts['pass'];
            }
            $rebuilt .= '@';
        }
        $rebuilt .= $parts['host'];
        if (! empty($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        if ($query !== []) {
            $rebuilt .= '?'.http_build_query($query);
        }
        if (! empty($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }
}
