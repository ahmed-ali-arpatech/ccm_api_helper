<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit07938174cac944a9344691531adbe08e
{
    public static $files = array (
        '8ba36fb9bd396d7fd9587d79b2dd9b54' => __DIR__ . '/../..' . '/src/Helpers/helpers.php',
    );

    public static $prefixLengthsPsr4 = array (
        'A' => 
        array (
            'Api\\Helper\\' => 11,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Api\\Helper\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit07938174cac944a9344691531adbe08e::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit07938174cac944a9344691531adbe08e::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit07938174cac944a9344691531adbe08e::$classMap;

        }, null, ClassLoader::class);
    }
}