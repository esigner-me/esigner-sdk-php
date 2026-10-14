<?php

declare(strict_types=1);

// The tests run without Composer: a minimal PSR-4 loader for Esigner\ → src/.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Esigner\\')) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    }
});
