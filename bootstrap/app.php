<?php

use App\Http\Middleware\AdminMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\CheckAdminApproval;
use App\Http\Middleware\CheckPermission;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
    //   $middleware->alias('admin_sign', CheckAdminApproval::class);

    $middleware->alias([
        'admin_sign' => CheckAdminApproval::class,
        'is_admin' => AdminMiddleware::class,
        'permission' => CheckPermission::class,
        'ability' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
    ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //testing ci cd
    })->create();
