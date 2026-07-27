<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Minimal, dependency-free SMTP client used to route PrestaShop emails
 * through the Message Globe relay (dashboard.messageglobe.com).
 *
 * It speaks the same SMTP/SMTPS protocol as the PHPMailer example provided by
 * Message Globe, but ships with no external dependencies so it works on any
 * PrestaShop hosting without Composer.
 */
class MessageGlobeSmtpMailer
{
    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var string ssl|tls|none */
    private $encryption;

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var int */
    private $timeout;

    /** @var array{email:string,name:string} */
    private $from = ['email' => '', 'name' => ''];

    /** @var array<int,array{email:string,name:string}> */
    private $to = [];

    /** @var array<int,array{email:string,name:string}> */
    private $cc = [];

    /** @var array<int,array{email:string,name:string}> */
    private $bcc = [];

    /** @var array{email:string,name:string}|null */
    private $replyTo = null;

    /** @var array<int,array{content:string,name:string,mime:string}> */
    private $attachments = [];

    /** @var resource|null */
    private $socket = null;

    public function __construct(array $config)
    {
        $this->host = trim((string) (isset($config['host']) ? $config['host'] : ''));
        $this->port = (int) (isset($config['port']) ? $config['port'] : 465);
        $this->encryption = strtolower(trim((string) (isset($config['encryption']) ? $config['encryption'] : 'ssl')));
        $this->username = (string) (isset($config['username']) ? $config['username'] : '');
        $this->password = (string) (isset($config['password']) ? $config['password'] : '');
        $this->timeout = (int) (isset($config['timeout']) ? $config['timeout'] : 20);

        if ($this->port <= 0) {
            $this->port = 465;
        }
        if (!in_array($this->encryption, ['ssl', 'tls', 'none'], true)) {
            $this->encryption = 'ssl';
        }
    }

    public function setFrom($email, $name = '')
    {
        $this->from = ['email' => $this->sanitizeAddress($email), 'name' => (string) $name];

        return $this;
    }

    public function addAddress($email, $name = '')
    {
        $email = $this->sanitizeAddress($email);
        if ($email !== '') {
            $this->to[] = ['email' => $email, 'name' => (string) $name];
        }

        return $this;
    }

    public function addCc($email, $name = '')
    {
        $email = $this->sanitizeAddress($email);
        if ($email !== '') {
            $this->cc[] = ['email' => $email, 'name' => (string) $name];
        }

        return $this;
    }

    public function addBcc($email, $name = '')
    {
        $email = $this->sanitizeAddress($email);
        if ($email !== '') {
            $this->bcc[] = ['email' => $email, 'name' => (string) $name];
        }

        return $this;
    }

    public function setReplyTo($email, $name = '')
    {
        $email = $this->sanitizeAddress($email);
        if ($email !== '') {
            $this->replyTo = ['email' => $email, 'name' => (string) $name];
        }

        return $this;
    }

    public function addAttachment($content, $name, $mime = 'application/octet-stream')
    {
        if ($content === null || $content === '') {
            return $this;
        }

        $this->attachments[] = [
            'content' => (string) $content,
            'name' => (string) $name,
            'mime' => (string) ($mime !== '' ? $mime : 'application/octet-stream'),
        ];

        return $this;
    }

    /**
     * @throws Exception when the SMTP conversation fails
     */
    public function send($subject, $htmlBody, $textBody = null)
    {
        if ($this->host === '') {
            throw new Exception('SMTP host is not configured.');
        }
        if (empty($this->to)) {
            throw new Exception('No recipients defined for the email.');
        }
        if ($this->from['email'] === '') {
            throw new Exception('No sender address defined for the email.');
        }

        $this->connect();

        try {
            $this->readResponse(220);

            $ehloHost = $this->getEhloHostname();
            $this->command('EHLO ' . $ehloHost, 250);

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);
                if (!$this->enableCrypto()) {
                    throw new Exception('Unable to start TLS encryption with the SMTP server.');
                }
                $this->command('EHLO ' . $ehloHost, 250);
            }

            if ($this->username !== '') {
                $this->authenticate();
            }

            $this->command('MAIL FROM:<' . $this->from['email'] . '>', 250);

            foreach ($this->allRecipients() as $recipient) {
                $this->command('RCPT TO:<' . $recipient['email'] . '>', [250, 251]);
            }

            $this->command('DATA', 354);
            $this->writeData($this->buildMessage($subject, $htmlBody, $textBody));
            $this->command('.', 250);
            $this->command('QUIT', 221, false);
        } finally {
            $this->close();
        }

        return true;
    }

    /**
     * Open a connection, negotiate encryption and authenticate, then disconnect
     * without sending a message. Used by the admin "Test connection" button to
     * validate the SMTP host/credentials before enabling the email takeover.
     *
     * @throws Exception when the connection, TLS handshake or authentication fails
     */
    public function verifyConnection()
    {
        if ($this->host === '') {
            throw new Exception('SMTP host is not configured.');
        }

        $this->connect();

        try {
            $this->readResponse(220);

            $ehloHost = $this->getEhloHostname();
            $this->command('EHLO ' . $ehloHost, 250);

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);
                if (!$this->enableCrypto()) {
                    throw new Exception('Unable to start TLS encryption with the SMTP server.');
                }
                $this->command('EHLO ' . $ehloHost, 250);
            }

            if ($this->username !== '') {
                $this->authenticate();
            }

            $this->command('QUIT', 221, false);
        } finally {
            $this->close();
        }

        return true;
    }

    private function connect()
    {
        $transport = $this->encryption === 'ssl' ? 'ssl://' . $this->host : $this->host;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $transport . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new Exception(sprintf('Unable to connect to SMTP server %s:%d (%s).', $this->host, $this->port, $errstr !== '' ? $errstr : ('error ' . $errno)));
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    private function enableCrypto()
    {
        if ($this->socket === null) {
            return false;
        }

        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }

        return (bool) @stream_socket_enable_crypto($this->socket, true, $crypto);
    }

    private function authenticate()
    {
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($this->username), 334);
        $this->command(base64_encode($this->password), 235);
    }

    /**
     * @param int|array<int,int> $expectedCode
     */
    private function command($command, $expectedCode, $expectResponse = true)
    {
        $this->writeData($command . "\r\n");

        if ($expectResponse) {
            $this->readResponse($expectedCode);
        }
    }

    private function writeData($data)
    {
        if ($this->socket === null) {
            throw new Exception('SMTP socket is not open.');
        }

        $bytes = @fwrite($this->socket, $data);
        if ($bytes === false) {
            throw new Exception('Failed to write to the SMTP socket.');
        }
    }

    /**
     * @param int|array<int,int> $expectedCode
     */
    private function readResponse($expectedCode)
    {
        if ($this->socket === null) {
            throw new Exception('SMTP socket is not open.');
        }

        $expected = is_array($expectedCode) ? $expectedCode : [$expectedCode];
        $response = '';
        $code = 0;

        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            $code = (int) substr($line, 0, 3);

            // A dash after the code means more lines follow.
            if (isset($line[3]) && $line[3] === '-') {
                continue;
            }

            break;
        }

        if (!in_array($code, $expected, true)) {
            throw new Exception(sprintf('Unexpected SMTP response (expected %s): %s', implode('/', $expected), trim($response)));
        }

        return $response;
    }

    private function close()
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * @return array<int,array{email:string,name:string}>
     */
    private function allRecipients()
    {
        return array_merge($this->to, $this->cc, $this->bcc);
    }

    private function buildMessage($subject, $htmlBody, $textBody)
    {
        $htmlBody = (string) $htmlBody;
        if ($textBody === null || $textBody === '') {
            $textBody = trim(html_entity_decode(strip_tags($htmlBody), ENT_QUOTES, 'UTF-8'));
        }

        $boundaryMixed = 'mg_mixed_' . bin2hex($this->randomBytes(12));
        $boundaryAlt = 'mg_alt_' . bin2hex($this->randomBytes(12));
        $hasAttachments = !empty($this->attachments);

        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $this->formatAddress($this->from);
        $headers[] = 'To: ' . $this->formatAddressList($this->to);
        if (!empty($this->cc)) {
            $headers[] = 'Cc: ' . $this->formatAddressList($this->cc);
        }
        if ($this->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $this->formatAddress($this->replyTo);
        }
        $headers[] = 'Subject: ' . $this->encodeHeader((string) $subject);
        $headers[] = 'Message-ID: <' . bin2hex($this->randomBytes(16)) . '@' . $this->getEhloHostname() . '>';
        $headers[] = 'MIME-Version: 1.0';

        $body = '';

        $alternative = '--' . $boundaryAlt . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($textBody)) . "\r\n"
            . '--' . $boundaryAlt . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody)) . "\r\n"
            . '--' . $boundaryAlt . "--\r\n";

        if ($hasAttachments) {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"';
            $body .= '--' . $boundaryMixed . "\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"' . "\r\n\r\n"
                . $alternative . "\r\n";

            foreach ($this->attachments as $attachment) {
                $body .= '--' . $boundaryMixed . "\r\n"
                    . 'Content-Type: ' . $attachment['mime'] . '; name="' . $this->encodeHeader($attachment['name']) . "\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . 'Content-Disposition: attachment; filename="' . $this->encodeHeader($attachment['name']) . "\"\r\n\r\n"
                    . chunk_split(base64_encode($attachment['content'])) . "\r\n";
            }

            $body .= '--' . $boundaryMixed . "--\r\n";
        } else {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"';
            $body .= $alternative;
        }

        return $this->normalizeLineEndings(implode("\r\n", $headers)) . "\r\n\r\n" . $this->dotStuff($body);
    }

    private function formatAddressList(array $addresses)
    {
        $formatted = [];
        foreach ($addresses as $address) {
            $formatted[] = $this->formatAddress($address);
        }

        return implode(', ', $formatted);
    }

    private function formatAddress(array $address)
    {
        if (empty($address['name'])) {
            return $address['email'];
        }

        return $this->encodeHeader($address['name']) . ' <' . $address['email'] . '>';
    }

    private function encodeHeader($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        // Pure ASCII (no special chars) can be sent as-is.
        if (preg_match('/^[\x20-\x7E]*$/', $value) && strpbrk($value, '"<>@,;:\\') === false) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function sanitizeAddress($email)
    {
        $email = trim((string) $email);
        // Strip CR/LF to prevent header injection.
        return str_replace(["\r", "\n"], '', $email);
    }

    /**
     * Escape leading dots on lines (SMTP transparency, RFC 5321).
     */
    private function dotStuff($body)
    {
        $body = $this->normalizeLineEndings($body);

        return preg_replace('/^\./m', '..', $body);
    }

    private function normalizeLineEndings($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);

        return str_replace("\n", "\r\n", $text);
    }

    private function getEhloHostname()
    {
        $host = '';
        if (!empty($this->from['email']) && strpos($this->from['email'], '@') !== false) {
            $host = substr(strrchr($this->from['email'], '@'), 1);
        }
        if ($host === '' && isset($_SERVER['SERVER_NAME'])) {
            $host = (string) $_SERVER['SERVER_NAME'];
        }

        return $host !== '' ? $host : 'localhost';
    }

    private function randomBytes($length)
    {
        if (function_exists('random_bytes')) {
            try {
                return random_bytes($length);
            } catch (Exception $e) {
                // fall through
            }
        }

        $bytes = '';
        for ($i = 0; $i < $length; ++$i) {
            $bytes .= chr(mt_rand(0, 255));
        }

        return $bytes;
    }
}
