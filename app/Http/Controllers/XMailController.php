<?php

namespace App\Http\Controllers;

use App\Models\MailAccount;
use App\Services\XMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class XMailController extends Controller
{
    public function login(Request $request): View|RedirectResponse
    {
        abort_unless(config('xpanel.xmail_enabled'), 404);
        if ($request->session()->has('xmail.account_id')) {
            return redirect()->route('xmail.index');
        }

        return view('mail.xmail-login', ['email' => (string) $request->query('email', '')]);
    }

    public function authenticate(Request $request, XMailService $mail): RedirectResponse
    {
        abort_unless(config('xpanel.xmail_enabled'), 404);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $email = strtolower($data['email']);
        $separator = strrpos($email, '@');
        $localPart = $separator === false ? '' : substr($email, 0, $separator);
        $domain = $separator === false ? '' : substr($email, $separator + 1);
        $account = MailAccount::with('domain')
            ->whereRaw('LOWER(local_part) = ?', [$localPart])
            ->where('status', '!=', 'error')
            ->whereHas('domain', fn ($query) => $query->whereRaw('LOWER(domain) = ?', [$domain]))
            ->first();

        if ($account === null || ! $mail->authenticate($email, $data['password'])) {
            return back()->withInput(['email' => $email])->withErrors(['email' => 'Correo o contraseña incorrectos.']);
        }

        $request->session()->regenerate();
        $request->session()->put('xmail', [
            'account_id' => $account->id,
            'email' => $email,
            'credential' => Crypt::encryptString($data['password']),
        ]);

        return redirect()->route('xmail.index');
    }

    public function index(Request $request): View
    {
        [$account] = $this->credentials($request);

        return view('mail.xmail', [
            'mailboxIdentity' => ['email' => $account->email, 'name' => $account->local_part],
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('xmail.login');
    }

    public function folders(Request $request, XMailService $mail): JsonResponse
    {
        [$account, $password] = $this->credentials($request);

        return response()->json(['folders' => $mail->folders($account->email, $password)]);
    }

    public function createFolder(Request $request, XMailService $mail): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:128']]);
        [$account, $password] = $this->credentials($request);
        $mail->createFolder($account->email, $password, $data['name']);

        return response()->json(['message' => 'Carpeta creada.'], 201);
    }

    public function deleteFolder(Request $request, XMailService $mail): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:128']]);
        [$account, $password] = $this->credentials($request);
        $mail->deleteFolder($account->email, $password, $data['name']);

        return response()->json(['message' => 'Carpeta eliminada.']);
    }

    public function messages(Request $request, XMailService $mail): JsonResponse
    {
        $data = $request->validate([
            'folder' => ['required', 'string', 'max:128'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
        ]);
        [$account, $password] = $this->credentials($request);

        return response()->json($mail->messages(
            $account->email,
            $password,
            $data['folder'],
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 25),
        ));
    }

    public function message(Request $request, XMailService $mail): JsonResponse
    {
        $data = $request->validate($this->messageRules());
        [$account, $password] = $this->credentials($request);

        return response()->json($mail->message($account->email, $password, $data['folder'], (int) $data['uid']));
    }

    public function flag(Request $request, XMailService $mail): JsonResponse
    {
        $data = $request->validate($this->messageRules() + [
            'flag' => ['required', Rule::in(['flagged'])],
            'set' => ['required', 'boolean'],
        ]);
        [$account, $password] = $this->credentials($request);
        $mail->setFlag($account->email, $password, $data['folder'], (int) $data['uid'], (bool) $data['set']);

        return response()->json(['message' => 'Mensaje actualizado.']);
    }

    public function move(Request $request, XMailService $mail): JsonResponse
    {
        $data = $request->validate($this->messageRules() + ['target_folder' => ['required', 'string', 'max:128']]);
        [$account, $password] = $this->credentials($request);
        $mail->move($account->email, $password, $data['folder'], (int) $data['uid'], $data['target_folder']);

        return response()->json(['message' => 'Mensaje movido.']);
    }

    public function deleteMessage(Request $request, XMailService $mail): JsonResponse
    {
        $data = $request->validate($this->messageRules());
        [$account, $password] = $this->credentials($request);
        $mail->delete($account->email, $password, $data['folder'], (int) $data['uid']);

        return response()->json(['message' => 'Mensaje eliminado.']);
    }

    public function send(Request $request, XMailService $mail): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'array', 'min:1', 'max:50'],
            'to.*' => ['required', 'email:rfc', 'max:254'],
            'cc' => ['nullable', 'array', 'max:50'],
            'cc.*' => ['required', 'email:rfc', 'max:254'],
            'bcc' => ['nullable', 'array', 'max:50'],
            'bcc.*' => ['required', 'email:rfc', 'max:254'],
            'subject' => ['required', 'string', 'max:998'],
            'text' => ['nullable', 'string', 'max:1048576'],
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string', 'max:4096'],
        ]);
        [$account, $password] = $this->credentials($request);
        $mail->send(
            $account->email,
            $password,
            $data['to'],
            $data['cc'] ?? [],
            $data['bcc'] ?? [],
            $data['subject'],
            $data['text'] ?? '',
            $data['in_reply_to'] ?? null,
            $data['references'] ?? null,
        );

        return response()->json(['message' => 'Mensaje enviado.'], 201);
    }

    public function attachment(Request $request, XMailService $mail): StreamedResponse
    {
        $data = $request->validate($this->messageRules() + ['part' => ['required', 'regex:/^\d+(?:\.\d+)*$/']]);
        [$account, $password] = $this->credentials($request);
        $attachment = $mail->attachment($account->email, $password, $data['folder'], (int) $data['uid'], $data['part']);

        return response()->streamDownload(
            fn () => print $attachment['contents'],
            $attachment['filename'],
            ['Content-Type' => $attachment['mime'], 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    /** @return array{0:MailAccount, 1:string} */
    private function credentials(Request $request): array
    {
        $account = MailAccount::with('domain')->find($request->session()->get('xmail.account_id'));
        $sessionEmail = strtolower((string) $request->session()->get('xmail.email'));
        if ($account === null || $account->status === 'error' || strtolower($account->email) !== $sessionEmail) {
            $request->session()->invalidate();
            abort(401, 'La cuenta de correo ya no está disponible.');
        }

        try {
            $password = Crypt::decryptString((string) $request->session()->get('xmail.credential'));
        } catch (\Throwable) {
            $request->session()->invalidate();
            abort(401, 'La sesión de XMail ya no es válida.');
        }

        return [$account, $password];
    }

    /** @return array<string, array<int, string>> */
    private function messageRules(): array
    {
        return [
            'folder' => ['required', 'string', 'max:128'],
            'uid' => ['required', 'integer', 'min:1'],
        ];
    }
}
