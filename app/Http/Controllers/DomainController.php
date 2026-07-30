<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\MailProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DomainController extends Controller
{
    public function index(Request $request): View
    {
        $domains = Domain::with('site')
            ->when($request->string('search')->trim()->isNotEmpty(), fn ($query) => $query->where('domain', 'like', '%'.$request->string('search')->trim().'%'))
            ->orderBy('domain')
            ->paginate(10)
            ->withQueryString();

        return view('domains.index', ['domains' => $domains]);
    }

    public function create(): View
    {
        return view('domains.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['domain' => strtolower(rtrim(trim((string) $request->input('domain')), '.'))]);
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255', 'unique:domains,domain', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/'],
        ]);

        Domain::create($data);

        return redirect()->route('domains.index')->with('status', "Dominio {$data['domain']} agregado.");
    }

    public function destroy(Domain $domain, MailProvisioner $provisioner): RedirectResponse
    {
        $name = $domain->domain;
        try {
            if ($domain->mailAccounts()->exists()) {
                $provisioner->removeDomain($domain);
            }
        } catch (\Throwable $exception) {
            try {
                $provisioner->sync();
            } catch (\Throwable) {
            }

            return back()->withErrors(['server' => $exception->getMessage()]);
        }
        $domain->delete();

        return redirect()->route('domains.index')->with('status', "Dominio {$name} eliminado.");
    }

    public function search(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $owned = Domain::pluck('domain')->map(fn ($domain) => strtolower($domain))->all();

        return view('domains.search', [
            'query' => $query,
            'domains' => $owned,
        ]);
    }

    public function transfers(): View
    {
        return view('domains.transfers', [
            'domains' => Domain::with('site')->orderBy('domain')->get(),
        ]);
    }
}
