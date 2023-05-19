<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2017-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Core;

use Closure;
use Exception;
use FacturaScripts\Core\Contract\ErrorControllerInterface;
use FacturaScripts\Core\Error\DefaultError;

final class Kernel
{
    /** @var array */
    private static $routes = [];

    /** @var Closure[] */
    private static $routesCallbacks = [];

    private static $startTime;

    public static function addRoute(string $route, string $controller, int $position = 0, string $customId = ''): void
    {
        // si el customId ya existe, eliminamos la ruta anterior
        if (!empty($customId)) {
            foreach (self::$routes as $key => $value) {
                if ($value['customId'] === $customId) {
                    unset(self::$routes[$key]);
                }
            }
        }

        // añadimos la nueva ruta
        self::$routes[$route] = [
            'controller' => $controller,
            'customId' => $customId,
            'position' => $position,
        ];
    }

    public static function addRoutes(Closure $closure): void
    {
        self::$routesCallbacks[] = $closure;
    }

    public static function getExecutionTime(int $decimals = 5): float
    {
        $diff = microtime(true) - self::$startTime;
        return round($diff, $decimals);
    }

    public static function init(): void
    {
        self::$startTime = microtime(true);

        // cargamos algunas constantes para dar soporte a versiones antiguas
        $constants = [
            'FS_CODPAIS' => ['property' => 'codpais', 'default' => 'ESP'],
            'FS_NF0' => ['property' => 'decimals', 'default' => 2],
            'FS_NF1' => ['property' => 'decimal_separator', 'default' => ','],
            'FS_NF2' => ['property' => 'thousands_separator', 'default' => ' '],
            'FS_CURRENCY_POS' => ['property' => 'currency_position', 'default' => 'right'],
            'FS_ITEM_LIMIT' => ['property' => 'item_limit', 'default' => 50],
        ];
        foreach ($constants as $key => $value) {
            if (!defined($key)) {
                define($key, Tools::settings('default', $value['property'], $value['default']));
            }
        }

        // cargamos el idioma almacenado en la cookie o el predeterminado
        $lang = $_COOKIE['fsLang'] ?? Tools::config('lang', 'es_ES');
        Tools::lang()->setDefaultLang($lang);
    }

    public static function rebuildRoutes(): void
    {
        self::$routes = [];
        self::loadDefaultRoutes();

        // cargamos la página por defecto
        $homePage = Tools::settings('default', 'homepage', 'Dashboard');

        // recorremos toda la lista de archivos de la carpeta Dinamic/Controller
        $dir = Tools::folder('Dinamic', 'Controller');
        foreach (Tools::folderScan($dir) as $file) {
            // si no es un archivo php, lo ignoramos
            if ('.php' !== substr($file, -4)) {
                continue;
            }

            // añadimos la ruta
            $route = substr($file, 0, -4);
            $controller = '\\FacturaScripts\\Dinamic\\Controller\\' . $route;
            self::addRoute('/' . $route, $controller);

            // si la ruta coincide con homepage, la añadimos como raíz
            if ($route === $homePage) {
                self::addRoute('/', $controller);
            }
        }

        // ejecutamos los callbacks para añadir rutas
        foreach (self::$routesCallbacks as $callback) {
            $callback(self::$routes);
        }

        // ordenamos colocando primero las que tienen una posición menor
        uasort(self::$routes, function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });
    }

    public static function run(string $url): void
    {
        $route = Tools::config('route', '');
        $relativeUrl = substr($url, strlen($route));

        try {
            self::loadRoutes();
            self::runController($relativeUrl);
        } catch (Exception $exception) {
            error_clear_last();

            $handler = self::getErrorHandler($exception);
            $handler->run();
        }
    }

    public static function saveRoutes(): bool
    {
        $filePath = Tools::folder('MyFiles', 'routes.json');
        $content = json_encode(self::$routes, JSON_PRETTY_PRINT);
        return false === file_put_contents($filePath, $content);
    }

    public static function shutdown(): void
    {
        $error = error_get_last();
        if (isset($error)) {
            // limpiamos el buffer si es necesario
            if (ob_get_length() > 0) {
                ob_end_clean();
            }

            http_response_code(500);

            // comprobamos si el content-type es json
            if (isset($_SERVER['CONTENT_TYPE']) && 'application/json' === $_SERVER['CONTENT_TYPE']) {
                header('Content-Type: application/json');
                echo json_encode(['error' => $error['message']]);
                return;
            }

            echo <<<END
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bootstrap demo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
  </head>
  <body class="bg-danger">
    <div class="container mt-5 mb-5">
        <div class="row justify-content-center">
            <div class="col-6">
                <div class="card shadow mb-5">
END;

            echo '<div class="card-body">';
            echo '<h1 class="mt-0">Fatal error ' . $error['type'] . '</h1>';
            echo '<p>' . nl2br($error['message']) . '</p>';
            echo '<p class="mb-0">File: ' . $error['file'] . ' Line: ' . $error['line'] . '</p>';
            echo '</div>';
            echo '<div class="card-footer">';

            // calculamos un hash para el error, de forma que en la web podamos dar respuesta automáticamente
            $errorMessage = self::cleanErrorMessage($error);
            $code = $error["type"] . substr($error["file"], strlen(FS_FOLDER)) . $error["line"] . $errorMessage;
            $hash = md5($code);
            $url = 'https://facturascripts.com/errores/' . $hash;

            echo '<a class="btn btn-secondary" href="' . $url . '">Read more / Leer más</a>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '<div class="row justify-content-center">';
            echo '<div class="col-4">';
            echo '<div class="card shadow">';
            echo '<img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($url) . '" alt="">';

            echo <<<END
            </div>
        </div>
    </body>
</html>
END;

        }
    }

    public static function version(): float
    {
        return 2023.02;
    }

    private static function cleanErrorMessage(array $error): string
    {
        $parts = explode(' in ' . $error['file'], $error['message']);
        return $parts[0];
    }

    private static function getErrorHandler(Exception $exception): ErrorControllerInterface
    {
        if ($exception instanceof KernelException) {
            $dynClass = '\\FacturaScripts\\Dinamic\\Error\\' . $exception->handler;
            if (class_exists($dynClass)) {
                return new $dynClass($exception);
            }

            $mainClass = '\\FacturaScripts\\Core\\Error\\' . $exception->handler;
            return new $mainClass($exception);
        }

        return new DefaultError($exception);
    }

    private static function loadDefaultRoutes(): void
    {
        // añadimos las rutas por defecto
        self::addRoute('/', '\\FacturaScripts\\Core\\Controller\\Dashboard', 1);
        self::addRoute('/AdminPlugins', '\\FacturaScripts\\Core\\Controller\\AdminPlugins', 1);
        self::addRoute('/api', '\\FacturaScripts\\Core\\Controller\\ApiRoot', 1);
        self::addRoute('/api/*', '\\FacturaScripts\\Core\\Controller\\ApiRoot', 1);
        self::addRoute('/Core/Assets/*', '\\FacturaScripts\\Core\\Controller\\Files', 1);
        self::addRoute('/cron', '\\FacturaScripts\\Core\\Controller\\Cron', 1);
        self::addRoute('/deploy', '\\FacturaScripts\\Core\\Controller\\Deploy', 1);
        self::addRoute('/Dinamic/Assets/*', '\\FacturaScripts\\Core\\Controller\\Files', 1);
        self::addRoute('/login', '\\FacturaScripts\\Core\\Controller\\Login', 1);
        self::addRoute('/MyFiles/*', '\\FacturaScripts\\Core\\Controller\\Myfiles', 1);
        self::addRoute('/node_modules/*', '\\FacturaScripts\\Core\\Controller\\Files', 1);
        self::addRoute('/Plugins/*', '\\FacturaScripts\\Core\\Controller\\Files', 1);
    }

    private static function loadRoutes(): void
    {
        if ('' === Tools::config('db_name', '')) {
            self::addRoute('/', '\\FacturaScripts\\Core\\Controller\\Installer', 1);
            self::addRoute('/Core/Assets/*', '\\FacturaScripts\\Core\\Controller\\Files', 1);
            self::addRoute('/node_modules/*', '\\FacturaScripts\\Core\\Controller\\Files', 1);
            return;
        }

        self::loadDefaultRoutes();

        // añadimos las rutas del archivo MyFiles/routes.json file
        $routesFile = Tools::folder('MyFiles', 'routes.json');
        if (false === file_exists($routesFile)) {
            return;
        }

        $routes = json_decode(file_get_contents($routesFile), true);
        if (false === is_array($routes)) {
            return;
        }

        foreach ($routes as $route => $params) {
            self::addRoute($route, $params['controller']);
        }

        // ordenamos colocando primero las que tienen una posición menor
        uasort(self::$routes, function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });
    }

    private static function runController(string $url): void
    {
        foreach (self::$routes as $route => $info) {
            $controller = $info['controller'];
            $class = explode('\\', $controller);
            $name = end($class);

            // coincidencia exacta
            if ($url === $route) {
                $app = new $controller($name, $url);
                $app->run();
                return;
            }

            // coincidencia con comodín
            if (str_ends_with($route, '*') && 0 === strncmp($url, $route, strlen($route) - 1)) {
                $app = new $controller($name, $url);
                $app->run();
                return;
            }
        }

        throw new KernelException('PageNotFound', $url);
    }
}
