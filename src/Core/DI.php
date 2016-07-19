<?php

/*
 * This file is part of the Сáша framework.
 *
 * (c) tchiotludo <http://github.com/tchiotludo>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare (strict_types = 1);

namespace Cawa\Core;

abstract class DI
{
    /**
     * @var array
     */
    private static $container = [];

    /**
     * @param string $namespace
     * @param string $name
     *
     * @return mixed|string
     */
    public static function get(string $namespace, string $name = null)
    {
        $name = $name ?: 'default';

        if (isset(self::$container[$namespace][$name])) {
            return self::$container[$namespace][$name];
        }

        return null;
    }

    /**
     * @param string $namespace
     * @param string $name
     * @param object $object
     *
     * @return mixed
     */
    public static function set(string $namespace, string $name = null, $object = null)
    {
        $name = $name ?: 'default';

        self::$container[$namespace][$name] = $object;

        return $object;
    }

    /**
     * @var Config
     */
    private static $config;

    /**
     * @return Config
     */
    public static function config(): Config
    {
        if (!self::$config) {
            self::$config = new Config();
        }

        return self::$config;
    }
}
