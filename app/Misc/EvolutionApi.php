<?php

namespace App\Misc;

/**
 * Minimal client for the Evolution API (WhatsApp).
 *
 * https://doc.evolution-api.com
 *
 * Credentials are configured per mailbox (Mailbox Settings → WhatsApp) and
 * stored in the mailbox meta.
 */
class EvolutionApi
{
    /**
     * @var string
     */
    protected $base_url;

    /**
     * @var string
     */
    protected $instance;

    /**
     * @var string
     */
    protected $api_key;

    /**
     * @var string
     */
    protected $last_error = '';

    public function __construct($base_url, $instance, $api_key)
    {
        $this->base_url = rtrim((string) $base_url, '/');
        $this->instance = (string) $instance;
        $this->api_key = (string) $api_key;
    }

    /**
     * Build a client from the mailbox settings.
     *
     * @param  \App\Mailbox $mailbox
     * @return self
     */
    public static function forMailbox($mailbox)
    {
        $settings = $mailbox->getWhatsappSettings();

        return new self($settings['url'], $settings['instance'], $settings['apikey']);
    }

    /**
     * Last error message, empty when the last request succeeded.
     *
     * @return string
     */
    public function getLastError()
    {
        return $this->last_error;
    }

    /**
     * Instance name, useful for log messages.
     *
     * @return string
     */
    public function getInstance()
    {
        return $this->instance;
    }

    /**
     * Whether the client has all the required credentials.
     *
     * @return bool
     */
    public function isConfigured()
    {
        return $this->base_url !== '' && $this->instance !== '' && $this->api_key !== '';
    }

    /**
     * Send a plain text message.
     *
     * @param  string $number Recipient number in international format.
     * @param  string $text
     * @return array|bool
     */
    public function sendText($number, $text)
    {
        return $this->request('POST', 'message/sendText/' . rawurlencode($this->instance), [
            'number' => self::normalizeNumber($number),
            'text' => $text,
        ]);
    }

    /**
     * Send a media message (image, document, audio or video).
     *
     * @param  string      $number
     * @param  string      $mediatype image|document|audio|video
     * @param  string      $media     Public URL or base64 payload.
     * @param  string|null $file_name
     * @param  string      $caption
     * @return array|bool
     */
    public function sendMedia($number, $mediatype, $media, $file_name = null, $caption = '')
    {
        $payload = [
            'number' => self::normalizeNumber($number),
            'mediatype' => $mediatype,
            'media' => $media,
            'caption' => $caption,
        ];

        if ($file_name) {
            $payload['fileName'] = $file_name;
        }

        return $this->request('POST', 'message/sendMedia/' . rawurlencode($this->instance), $payload);
    }

    /**
     * Connection state of the instance (open, connecting, close).
     *
     * @return array|bool
     */
    public function connectionState()
    {
        return $this->request('GET', 'instance/connectionState/' . rawurlencode($this->instance));
    }

    /**
     * Perform the HTTP request.
     *
     * @param  string     $method
     * @param  string     $path
     * @param  array|null $payload
     * @return array|bool Decoded response, or false on error.
     */
    protected function request($method, $path, array $payload = null)
    {
        $this->last_error = '';

        if (!$this->isConfigured()) {
            $this->last_error = __('WhatsApp credentials are not configured for this mailbox');

            return false;
        }

        try {
            $options = \Helper::setGuzzleDefaultOptions([
                'headers' => [
                    'apikey' => $this->api_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);

            if ($payload !== null) {
                $options['json'] = $payload;
            }

            $client = new \GuzzleHttp\Client();
            $response = $client->request($method, $this->base_url . '/' . ltrim($path, '/'), $options);

            $body = (string) $response->getBody();
            $decoded = json_decode($body, true);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return is_array($decoded) ? $decoded : [];
            }

            $this->last_error = 'HTTP ' . $response->getStatusCode() . ': ' . mb_substr($body, 0, 200);
        } catch (\Exception $e) {
            $this->last_error = $e->getMessage();
        }

        return false;
    }

    /**
     * Evolution API expects the number in international format without symbols.
     *
     * @param  string $number
     * @return string
     */
    public static function normalizeNumber($number)
    {
        return preg_replace('/[^0-9]/', '', (string) $number);
    }
}
