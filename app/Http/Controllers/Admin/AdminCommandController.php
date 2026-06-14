<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class AdminCommandController extends Controller
{
    public function index(): View
    {
        $commands = config('contratacion.admin.commands', []);

        return view('admin.commands', [
            'commands' => $commands,
            'output' => session('command_output'),
            'executedCommand' => session('executed_command'),
        ]);
    }

    public function run(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'command' => ['required', 'string'],
        ]);

        $input = trim($request->input('command'));
        $allowed = config('contratacion.admin.commands', []);

        // Whitelist estricta: solo acepta comandos exactos de la lista
        if (! in_array($input, $allowed, true)) {
            return back()->withErrors(['command' => 'Comando no permitido.']);
        }

        // Parsear comando y argumentos de forma segura
        $parts = explode(' ', $input, 2);
        $command = $parts[0];
        $arguments = [];

        if (isset($parts[1])) {
            foreach (explode(' ', $parts[1]) as $arg) {
                // Solo aceptar flags --key o --key=value
                if (str_starts_with($arg, '--') && preg_match('/^--[a-z][a-z0-9_-]*(=\S+)?$/i', $arg)) {
                    $kv = explode('=', substr($arg, 2), 2);
                    $arguments['--'.$kv[0]] = $kv[1] ?? true;
                }
            }
        }

        Artisan::call($command, $arguments);
        $output = Artisan::output();

        return back()
            ->with('command_output', $output)
            ->with('executed_command', $input);
    }
}
