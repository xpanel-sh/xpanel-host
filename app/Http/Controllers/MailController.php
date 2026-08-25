<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\MailAccount;
use App\Models\ServerIpAddress;
use App\Services\MailDnsService;
use App\Services\MailProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MailController extends Controller
{
    public function index(Request $request, MailDnsService $dns): View
    {
        $search = $request->string('search')->trim()->toString();
        $accounts = MailAccount::with('domain.mailSettings.serverIpAddress')
            ->when($search !== '', function ($query) use ($search): void {
                if (str_contains($search, '@')) {
                    [$localPart, $domain] = explode('@', $search, 2);
                    $query->where('local_part', 'like', '%'.$localPart.'%')
                        ->whereHas('domain', fn ($domainQuery) => $domainQuery->where('domain', 'like', '%'.$domain.'%'));
                } else {
                    $query->where(fn ($accountQuery) => $accountQuery
                        ->where('local_part', 'like', '%'.$search.'%')
                        ->orWhereHas('domain', fn ($domainQuery) => $domainQuery->where('domain', 'like', '%'.$search.'%')));
                }
            })
            ->orderBy('local_part')->paginate(10)->withQueryString();

        return view('mail.index', [
            'accounts' => $accounts,
            'mailStats' => [
                'active' => MailAccount::where('status', 'active')->count(),
                'domains' => Domain::whereHas('mailAccounts')->count(),
                'dedicated_ips' => ServerIpAddress::count(),
            ],
            'mailDomains' => $accounts->getCollection()
                ->pluck('domain')
                ->unique('id')
                ->mapWithKeys(fn (Domain $domain): array => [$domain->id => [
                    'domain' => $domain,
                    'records' => $dns->expected($domain),
                ]]),
            'serverIpAddresses' => ServerIpAddress::orderBy('label')->orderBy('ip_address')->get(),
            'search' => $search,
        ]);
    }

    public function create(): View
    {
        return view('mail.create', [
            'domains' => Domain::orderBy('domain')->get(),
        ]);
    }

    public function store(Request $request, MailProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'local_part' => 'required|string|max:64|regex:/^[a-zA-Z0-9._-]+$/',
            'domain_id' => 'required|exists:domains,id',
            'password' => 'required|string|min:12',
            'quota_mb' => 'required|integer|min:128|max:102400',
            'hourly_send_limit' => 'sometimes|integer|min:10|max:10000',
            'daily_send_limit' => 'sometimes|integer|min:10|max:100000',
        ]);
        $data['hourly_send_limit'] ??= 100;
        $data['daily_send_limit'] ??= 500;
        if ($data['daily_send_limit'] < $data['hourly_send_limit']) {
            return back()->withInput($request->except('password'))->withErrors([
                'daily_send_limit' => 'El límite diario debe ser mayor o igual que el límite por hora.',
            ]);
        }

        $exists = MailAccount::where('domain_id', $data['domain_id'])
            ->where('local_part', $data['local_part'])
            ->exists();
        abort_if($exists, 422, 'Ya existe una cuenta de correo con ese nombre en ese dominio.');

        $account = MailAccount::create($data + ['status' => 'provisioning']);
        try {
            $provisioner->apply($account);
        } catch (\Throwable $exception) {
            $account->delete();

            return back()->withInput($request->except('password'))->withErrors(['server' => $exception->getMessage()]);
        }

        return redirect()->route('mail.index')->with('status', "Cuenta {$account->email} creada.");
    }

    public function destroy(MailAccount $mailAccount, MailProvisioner $provisioner): RedirectResponse
    {
        $email = $mailAccount->email;
        try {
            $provisioner->remove($mailAccount);
        } catch (\Throwable $exception) {
            try {
                $provisioner->sync();
            } catch (\Throwable) {
            }

            return back()->withErrors(['server' => $exception->getMessage()]);
        }
        $mailAccount->delete();

        return redirect()->route('mail.index')->with('status', "Cuenta {$email} eliminada.");
    }

    public function resetPassword(Request $request, MailAccount $mailAccount, MailProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'password' => 'required|string|min:12',
        ]);

        $originalHash = $mailAccount->getRawOriginal('password');
        $mailAccount->update(['password' => $data['password'], 'status' => 'provisioning']);
        try {
            $provisioner->apply($mailAccount);
        } catch (\Throwable $exception) {
            DB::table('mail_accounts')->where('id', $mailAccount->id)->update([
                'password' => $originalHash,
                'status' => 'error',
                'updated_at' => now(),
            ]);
            try {
                $provisioner->sync();
            } catch (\Throwable) {
            }

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return redirect()->route('mail.index')->with('status', "Contrasena de {$mailAccount->email} actualizada.");
    }

    public function updateLimits(Request $request, MailAccount $mailAccount, MailProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'hourly_send_limit' => 'required|integer|min:10|max:10000',
            'daily_send_limit' => 'required|integer|min:10|max:100000|gte:hourly_send_limit',
        ]);
        $original = $mailAccount->only(['hourly_send_limit', 'daily_send_limit', 'status']);
        $mailAccount->update($data + ['status' => 'provisioning']);
        try {
            $provisioner->apply($mailAccount);
        } catch (\Throwable $exception) {
            $mailAccount->forceFill(array_merge($original, ['status' => 'error']))->save();
            try {
                $provisioner->sync();
            } catch (\Throwable) {
            }

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return redirect()->route('mail.index')->with('status', "Límites de {$mailAccount->email} actualizados.");
    }
}
