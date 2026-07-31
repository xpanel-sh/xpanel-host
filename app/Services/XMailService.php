<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

class XMailService
{
    // Direct SSL (port 993) instead of STARTTLS on 143: PHP's IMAP extension
    // (the old c-client library) has a long-documented history of aborting
    // mid-handshake when negotiating STARTTLS with Dovecot, even with
    // novalidate-cert set -- direct SSL avoids that upgrade-in-place step
    // entirely and is the standard workaround.
    private const SERVER = '{127.0.0.1:993/imap/ssl/novalidate-cert}';

    public function authenticate(string $email, #[\SensitiveParameter] string $password): bool
    {
        if (! function_exists('imap_open')) {
            throw new \RuntimeException('La extensión PHP IMAP no está instalada.');
        }

        $connection = @imap_open(self::SERVER.'INBOX', $email, $password, OP_READONLY, 1);
        if ($connection === false) {
            imap_errors();

            return false;
        }

        imap_close($connection);

        return true;
    }

    /** @return array<int, array{name:string, unseen:int}> */
    public function folders(string $email, #[\SensitiveParameter] string $password): array
    {
        $connection = $this->connect($email, $password);
        try {
            $this->ensureDefaultFolders($connection);
            $mailboxes = imap_getmailboxes($connection, self::SERVER, '*') ?: [];
            $folders = [];
            foreach ($mailboxes as $mailbox) {
                $name = $this->decodeFolder(substr($mailbox->name, strlen(self::SERVER)));
                $status = @imap_status($connection, self::SERVER.$this->encodeFolder($name), SA_UNSEEN);
                $folders[] = ['name' => $name, 'unseen' => $status === false ? 0 : (int) $status->unseen];
            }

            usort($folders, fn (array $a, array $b): int => $this->folderRank($a['name']) <=> $this->folderRank($b['name']) ?: strcasecmp($a['name'], $b['name']));

            return $folders;
        } finally {
            imap_close($connection);
        }
    }

    /** @return array{messages:array<int, array<string, mixed>>, total:int} */
    public function messages(string $email, #[\SensitiveParameter] string $password, string $folder, int $page, int $perPage): array
    {
        $connection = $this->connect($email, $password, $folder, true);
        try {
            $uids = imap_sort($connection, SORTARRIVAL, true, SE_UID) ?: [];
            $total = count($uids);
            $uids = array_slice($uids, ($page - 1) * $perPage, $perPage);
            $messages = [];

            foreach ($uids as $uid) {
                $overview = imap_fetch_overview($connection, (string) $uid, FT_UID)[0] ?? null;
                if ($overview === null) {
                    continue;
                }
                $from = $this->firstAddress((string) ($overview->from ?? ''));
                $messages[] = [
                    'uid' => (int) $uid,
                    'from' => $from['name'] ?: $from['email'],
                    'from_address' => $from['email'],
                    'subject' => $this->decodeHeader((string) ($overview->subject ?? '')),
                    'date' => isset($overview->udate) ? date(DATE_ATOM, (int) $overview->udate) : null,
                    'seen' => ! empty($overview->seen),
                    'flagged' => ! empty($overview->flagged),
                    'preview' => '',
                ];
            }

            return ['messages' => $messages, 'total' => $total];
        } finally {
            imap_close($connection);
        }
    }

    /** @return array<string, mixed> */
    public function message(string $email, #[\SensitiveParameter] string $password, string $folder, int $uid): array
    {
        $connection = $this->connect($email, $password, $folder);
        try {
            $messageNumber = imap_msgno($connection, $uid);
            if ($messageNumber < 1) {
                throw new \RuntimeException('El mensaje ya no existe.');
            }

            $overview = imap_fetch_overview($connection, (string) $uid, FT_UID)[0] ?? throw new \RuntimeException('No se pudo leer el mensaje.');
            $header = imap_headerinfo($connection, $messageNumber);
            if ($header === false) {
                throw new \RuntimeException('No se pudo leer la cabecera del mensaje.');
            }
            $content = ['text' => '', 'html' => '', 'attachments' => []];
            $structure = imap_fetchstructure($connection, $uid, FT_UID);
            if ($structure !== false) {
                $this->collectParts($connection, $uid, $structure, '', $content);
            }

            $from = $this->addressObject($header->from[0] ?? null);
            $to = array_map(fn ($item): string => $this->addressObject($item)['email'], $header->to ?? []);
            $html = $content['html'] !== '' ? $this->sanitizeHtml($content['html']) : '';
            if (! imap_setflag_full($connection, (string) $uid, '\\Seen', ST_UID)) {
                throw new \RuntimeException('No se pudo marcar el mensaje como leído.');
            }

            return [
                'uid' => $uid,
                'folder' => $folder,
                'from' => $from['name'] ?: $from['email'],
                'from_address' => $from['email'],
                'to' => array_values(array_filter($to)),
                'subject' => $this->decodeHeader((string) ($overview->subject ?? '')),
                'date' => isset($overview->udate) ? date(DATE_ATOM, (int) $overview->udate) : null,
                'message_id' => trim((string) ($header->message_id ?? '')),
                'references' => trim((string) ($header->references ?? '')),
                'seen' => true,
                'flagged' => ! empty($overview->flagged),
                'text' => $content['text'] !== '' ? $content['text'] : trim(strip_tags($html)),
                'html' => $html,
                'attachments' => $content['attachments'],
            ];
        } finally {
            imap_close($connection);
        }
    }

    /** @return array{contents:string, filename:string, mime:string} */
    public function attachment(string $email, #[\SensitiveParameter] string $password, string $folder, int $uid, string $part): array
    {
        $connection = $this->connect($email, $password, $folder, true);
        try {
            $structure = imap_fetchstructure($connection, $uid, FT_UID);
            if ($structure === false) {
                throw new \RuntimeException('No se pudo leer el adjunto.');
            }
            $item = $this->findPart($structure, $part);
            $filename = $this->partFilename($item);
            if ($filename === '') {
                throw new \RuntimeException('El adjunto solicitado no existe.');
            }
            if ((int) ($item->bytes ?? 0) > (int) config('xpanel.xmail_attachment_max_bytes')) {
                throw new \RuntimeException('El adjunto supera el límite de descarga de XMail.');
            }
            $body = (string) imap_fetchbody($connection, $uid, $part, FT_UID | FT_PEEK);
            $safeFilename = preg_replace('/[\x00-\x1F\x7F]/u', '_', basename(str_replace('\\', '/', $this->decodeHeader($filename))));

            return [
                'contents' => $this->decodeBody($body, (int) ($item->encoding ?? 0)),
                'filename' => $safeFilename ?: 'attachment',
                'mime' => $this->mimeType($item),
            ];
        } finally {
            imap_close($connection);
        }
    }

    public function setFlag(string $email, #[\SensitiveParameter] string $password, string $folder, int $uid, bool $set): void
    {
        $connection = $this->connect($email, $password, $folder);
        try {
            $ok = $set
                ? imap_setflag_full($connection, (string) $uid, '\\Flagged', ST_UID)
                : imap_clearflag_full($connection, (string) $uid, '\\Flagged', ST_UID);
            if (! $ok) {
                throw new \RuntimeException('No se pudo actualizar el mensaje.');
            }
        } finally {
            imap_close($connection);
        }
    }

    public function move(string $email, #[\SensitiveParameter] string $password, string $folder, int $uid, string $target): void
    {
        $connection = $this->connect($email, $password, $folder);
        try {
            $this->assertFolder($target);
            if (! imap_mail_move($connection, (string) $uid, $this->encodeFolder($target), CP_UID) || ! imap_expunge($connection)) {
                throw new \RuntimeException('No se pudo mover el mensaje.');
            }
        } finally {
            imap_close($connection);
        }
    }

    public function delete(string $email, #[\SensitiveParameter] string $password, string $folder, int $uid): void
    {
        $connection = $this->connect($email, $password, $folder);
        try {
            if (! imap_delete($connection, (string) $uid, FT_UID) || ! imap_expunge($connection)) {
                throw new \RuntimeException('No se pudo eliminar el mensaje.');
            }
        } finally {
            imap_close($connection);
        }
    }

    public function createFolder(string $email, #[\SensitiveParameter] string $password, string $folder): void
    {
        $this->assertFolder($folder);
        $connection = $this->connect($email, $password);
        try {
            if (! imap_createmailbox($connection, self::SERVER.$this->encodeFolder($folder))) {
                throw new \RuntimeException('No se pudo crear la carpeta.');
            }
        } finally {
            imap_close($connection);
        }
    }

    public function deleteFolder(string $email, #[\SensitiveParameter] string $password, string $folder): void
    {
        if (in_array(strtolower($folder), ['inbox', 'sent', 'drafts', 'junk', 'trash'], true)) {
            throw new \RuntimeException('No se puede borrar una carpeta del sistema.');
        }
        $this->assertFolder($folder);
        $connection = $this->connect($email, $password);
        try {
            if (! imap_deletemailbox($connection, self::SERVER.$this->encodeFolder($folder))) {
                throw new \RuntimeException('No se pudo eliminar la carpeta.');
            }
        } finally {
            imap_close($connection);
        }
    }

    /** @param array<int, string> $to @param array<int, string> $cc @param array<int, string> $bcc */
    public function send(string $email, #[\SensitiveParameter] string $password, array $to, array $cc, array $bcc, string $subject, string $text, ?string $inReplyTo = null, ?string $references = null): void
    {
        $message = (new Email)->from($email)->to(...$to)->subject($subject)->text($text);
        if ($cc !== []) {
            $message->cc(...$cc);
        }
        if ($bcc !== []) {
            $message->bcc(...$bcc);
        }
        if ($inReplyTo) {
            $message->getHeaders()->addIdHeader('In-Reply-To', trim($inReplyTo, '<>'));
        }
        if ($references) {
            $message->getHeaders()->addTextHeader('References', preg_replace('/[\r\n]+/', ' ', $references));
        }

        $transport = new EsmtpTransport('127.0.0.1', 587, false);
        $transport->setAutoTls(true)->setRequireTls(true)->setUsername($email)->setPassword($password);
        $transport->getStream()->setStreamOptions(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]]);
        (new Mailer($transport))->send($message);

        $connection = $this->connect($email, $password);
        try {
            $this->ensureDefaultFolders($connection);
            @imap_append($connection, self::SERVER.'Sent', $message->toString(), '\\Seen');
        } finally {
            imap_close($connection);
        }
    }

    private function connect(string $email, #[\SensitiveParameter] string $password, string $folder = 'INBOX', bool $readOnly = false): mixed
    {
        if (! function_exists('imap_open')) {
            throw new \RuntimeException('La extensión PHP IMAP no está instalada.');
        }
        $this->assertFolder($folder);
        $flags = $readOnly ? OP_READONLY : 0;
        $connection = @imap_open(self::SERVER.$this->encodeFolder($folder), $email, $password, $flags, 1);
        if ($connection === false) {
            imap_errors();
            throw new \RuntimeException('No se pudo conectar al buzón. Vuelve a iniciar sesión.');
        }

        return $connection;
    }

    private function ensureDefaultFolders(mixed $connection): void
    {
        foreach (['Sent', 'Drafts', 'Junk', 'Trash'] as $folder) {
            $status = @imap_status($connection, self::SERVER.$folder, SA_MESSAGES);
            if ($status === false) {
                @imap_createmailbox($connection, self::SERVER.$folder);
            }
        }
    }

    private function assertFolder(string $folder): void
    {
        if ($folder === '' || mb_strlen($folder) > 128 || preg_match('/[\x00-\x1F\x7F}]/u', $folder)) {
            throw new \InvalidArgumentException('La carpeta no es válida.');
        }
    }

    private function encodeFolder(string $folder): string
    {
        if (function_exists('imap_utf8_to_mutf7')) {
            return imap_utf8_to_mutf7($folder);
        }

        return function_exists('imap_utf7_encode') ? imap_utf7_encode($folder) : $folder;
    }

    private function decodeFolder(string $folder): string
    {
        if (function_exists('imap_mutf7_to_utf8')) {
            return imap_mutf7_to_utf8($folder);
        }

        return function_exists('imap_utf7_decode') ? imap_utf7_decode($folder) : $folder;
    }

    private function folderRank(string $folder): int
    {
        return array_search(strtolower($folder), ['inbox', 'sent', 'drafts', 'junk', 'trash'], true) ?: (strtolower($folder) === 'inbox' ? 0 : 50);
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $decoded = '';
        $parts = imap_mime_header_decode($value);
        if (! is_array($parts)) {
            return $value;
        }
        foreach ($parts as $part) {
            $charset = strtoupper((string) $part->charset);
            $text = (string) $part->text;
            if (in_array($charset, ['', 'DEFAULT', 'UTF-8', 'US-ASCII'], true)) {
                $decoded .= $text;

                continue;
            }
            try {
                $decoded .= mb_convert_encoding($text, 'UTF-8', $charset) ?: $text;
            } catch (\ValueError) {
                $decoded .= $text;
            }
        }

        return $decoded;
    }

    /** @return array{name:string, email:string} */
    private function firstAddress(string $value): array
    {
        $addresses = imap_rfc822_parse_adrlist($value, 'localhost');

        return $this->addressObject(is_array($addresses) ? ($addresses[0] ?? null) : null);
    }

    /** @return array{name:string, email:string} */
    private function addressObject(mixed $address): array
    {
        if (! is_object($address) || empty($address->mailbox) || empty($address->host) || $address->host === '.SYNTAX-ERROR.') {
            return ['name' => '', 'email' => ''];
        }

        return [
            'name' => $this->decodeHeader((string) ($address->personal ?? '')),
            'email' => strtolower($address->mailbox.'@'.$address->host),
        ];
    }

    /** @param array{text:string, html:string, attachments:array<int, array<string, mixed>>} $content */
    private function collectParts(mixed $connection, int $uid, object $part, string $number, array &$content): void
    {
        if (! empty($part->parts)) {
            foreach ($part->parts as $index => $child) {
                $childNumber = $number === '' ? (string) ($index + 1) : $number.'.'.($index + 1);
                $this->collectParts($connection, $uid, $child, $childNumber, $content);
            }

            return;
        }

        $partNumber = $number === '' ? '1' : $number;
        $filename = $this->partFilename($part);
        if ($filename !== '') {
            $content['attachments'][] = [
                'part' => $partNumber,
                'filename' => basename(str_replace('\\', '/', $this->decodeHeader($filename))),
                'size' => (int) ($part->bytes ?? 0),
                'mime' => $this->mimeType($part),
            ];

            return;
        }

        if ((int) ($part->type ?? -1) !== 0) {
            return;
        }
        if ((int) ($part->bytes ?? 0) > 10 * 1024 * 1024) {
            if ($content['text'] === '') {
                $content['text'] = '[El cuerpo del mensaje supera el límite de visualización de XMail. Descárgalo mediante un cliente IMAP.]';
            }

            return;
        }
        $body = (string) imap_fetchbody($connection, $uid, $partNumber, FT_UID | FT_PEEK);
        $body = $this->decodeBody($body, (int) ($part->encoding ?? 0));
        $charset = $this->partParameter($part, 'charset');
        if ($charset && ! in_array(strtoupper($charset), ['UTF-8', 'US-ASCII'], true)) {
            try {
                $body = mb_convert_encoding($body, 'UTF-8', $charset);
            } catch (\ValueError) {
                // Preserve the original bytes when a malformed message declares an unknown charset.
            }
        }
        if (strtoupper((string) ($part->subtype ?? 'PLAIN')) === 'HTML' && $content['html'] === '') {
            $content['html'] = $body;
        } elseif ($content['text'] === '') {
            $content['text'] = $body;
        }
    }

    private function decodeBody(string $body, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($body, true) ?: '',
            4 => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function partFilename(object $part): string
    {
        return $this->partParameter($part, 'filename') ?: $this->partParameter($part, 'name') ?: '';
    }

    private function partParameter(object $part, string $name): ?string
    {
        foreach (array_merge($part->parameters ?? [], $part->dparameters ?? []) as $parameter) {
            if (strtolower((string) $parameter->attribute) === strtolower($name)) {
                return (string) $parameter->value;
            }
        }

        return null;
    }

    private function mimeType(object $part): string
    {
        $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];

        return ($types[(int) ($part->type ?? 7)] ?? 'application').'/'.strtolower((string) ($part->subtype ?? 'octet-stream'));
    }

    private function findPart(object $structure, string $wanted, string $number = ''): object
    {
        if (! preg_match('/^\d+(?:\.\d+)*$/', $wanted)) {
            throw new \InvalidArgumentException('El adjunto no es válido.');
        }
        if ($number === $wanted || ($number === '' && $wanted === '1' && empty($structure->parts))) {
            return $structure;
        }
        foreach ($structure->parts ?? [] as $index => $child) {
            $childNumber = $number === '' ? (string) ($index + 1) : $number.'.'.($index + 1);
            if ($childNumber === $wanted || str_starts_with($wanted, $childNumber.'.')) {
                return $this->findPart($child, $wanted, $childNumber);
            }
        }

        throw new \RuntimeException('El adjunto solicitado no existe.');
    }

    private function sanitizeHtml(string $html): string
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="xmail-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'meta', 'link', 'base'] as $tag) {
            while (($nodes = $document->getElementsByTagName($tag))->length > 0) {
                $nodes->item(0)?->parentNode?->removeChild($nodes->item(0));
            }
        }
        foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);
                if (str_starts_with($name, 'on') || in_array($name, ['src', 'srcset', 'background', 'action', 'formaction'], true)
                    || ($name === 'style' && preg_match('/url\s*\(|expression\s*\(/i', $value))
                    || ($name === 'href' && ! preg_match('/^(?:https?:|mailto:|#)/i', $value))) {
                    $element->removeAttribute($attribute->name);
                }
            }
            if ($element->hasAttribute('href')) {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
        $root = $document->getElementById('xmail-root');
        $result = '';
        foreach ($root?->childNodes ?? [] as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }
}
