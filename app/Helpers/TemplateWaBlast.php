<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TemplateWaBlast
{

    public static function templateKuningan(string $recipient, string $nama, string $no_pol, string $media_template)
    {
        $url = env('NUSA_SMS_URL', '');
        $apiKey = env('NUSA_SMS_API_KEY', '');
        $gateway_id = env('NUSA_SMS_GATEWAY_ID', '');


        if ($media_template === 'video') {

            $template_name = 'bappenda_kuningan_vidio';

            $header = [
                'type'    => 'video',
                'link'    => 'http://example.com/video.mp4',
                'caption' => 'Bayar Pajak Tepat Waktu, Kota Kita Maju'
            ];
        } elseif ($media_template === 'text') {

            $template_name = 'bappenda_kuningan_text';
            $header = null; // ✅ bukan string!

        } else { // image

            $template_name = 'kuningan_image_fx';

            $header = [
                'type'     => 'image',
                'link'     => 'http://example.com/image.jpg',
                'filename' => 'kuningan_images.jpeg',
                'caption'  => 'Bayar Pajak Tepat Waktu, Kota Kita Maju'
            ];
        }

        $payload = [
            'recipient'  => $recipient,
            'gateway_id' => $gateway_id,
            'template'   => [
                'name'     => $template_name,
                'language' => 'id',
            ],
            'body' => [
                ['type' => 'text', 'text' => $nama],
                ['type' => 'text', 'text' => $no_pol],
            ]
        ];
        // ✅ hanya tambahkan header jika ada
        if ($header !== null) {
            $payload['header'] = $header;
        }

        try {
            $response = Http::timeout(60)->withHeaders([
                'Accept' => 'application/json',
                'APIKey' => $apiKey,
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            $json = $response->json();
            // Log::info('Json Nusa sms Response', $json ."\r header Tamplate". $headers);
            Log::info('Json Nusa sms Response', [
                'response' => $json,
                'payload' => $payload
            ]);
            return [
                'error'      => $json['error'] ?? true,
                'message'    => $json['message'] ?? 'Unknown message',
                'data'       => $json['data'] ?? [],
                'status'     => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error("NusaSMS API Error", [
                "recipient" => $recipient,
                "error" => $e->getMessage(),
            ]);

            return [
                'error'   => true,
                'message' => $e->getMessage(),
                'data'    => [],
                'status'  => 500,
            ];
        }
    }
}
