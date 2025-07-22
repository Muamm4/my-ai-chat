<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Generator;
use Throwable;
use App\Models\Chat;
use Prism\Prism\Prism;
use App\Models\Message;
use App\Enums\ModelName;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ChunkType;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\ChatStreamRequest;
use Illuminate\Support\Facades\Response;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

final class ChatStreamController extends Controller
{
    public function __invoke(ChatStreamRequest $request, Chat $chat): StreamedResponse
    {
        $userMessage = $request->string('message')->trim()->value();
        $model = $request->enum('model', ModelName::class, ModelName::GEMINI_2_5_FLASH_IMAGE_PREVIEW);

        $chat->messages()->create([
            'role' => 'user',
            'parts' => [
                ChunkType::Text->value => $userMessage,
            ],
            'attachments' => '[]',
        ]);

        $messages = $this->buildConversationHistory($chat);
        return Response::stream(function () use ($chat, $messages, $model): Generator {
            $parts = [];

            if ($model->getProvider() === Provider::Gemini && $model->value === ModelName::GEMINI_2_5_FLASH_IMAGE_PREVIEW->value) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image-preview:generateContent');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);

                // Build the request payload
                $payload = [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => $messages[count($messages) - 1]->content]
                            ]
                        ]
                    ]
                ];

                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'x-goog-api-key: ' . env('GEMINI_API_KEY'),
                    'Content-Type: application/json',
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($error) {
                    Log::error('Gemini API request failed: ' . $error);
                    throw new \RuntimeException('Failed to communicate with Gemini API');
                }

                if ($httpCode !== 200) {
                    Log::error('Gemini API returned error: ' . $response);
                    throw new \RuntimeException('Gemini API returned an error');
                }

                $responseData = json_decode($response, true);

                // Process the response
                if (!empty($responseData['candidates'][0]['content']['parts'])) {
                    foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
                        if (isset($part['text'])) {
                            if (! isset($parts['text'])) {
                                $parts['text'] = '';
                            }
                            $parts['text'] .= $part['text'];
                            yield json_encode([
                                'chunkType' => 'text',
                                'content' => $part['text']
                            ]) . "\n";
                            
                        } elseif (isset($part['inlineData'])) {
                            $mimeType = $part['inlineData']['mimeType'] ?? 'image/png';
                            $fileExtension = explode('/', $mimeType)[1] ?? 'png';
                            $fileName = 'gemini_image_' . time() . '.' . $fileExtension;
                            $filePath = storage_path('app/public/' . $fileName);

                            // Save the image file
                            file_put_contents($filePath, base64_decode($part['inlineData']['data']));

                            // Yield the image URL or path
                            if (! isset($parts['image'])) {
                                $parts['image'] = '';
                            }
                            $parts['image'] .= $part['inlineData']['data'];
                            yield json_encode([
                                'chunkType' => 'image',
                                'content' => $part['inlineData']['data'],
                                'mimeType' => $mimeType
                            ]) . "\n";
                        }
                    }
                    if ($parts !== []) {
                        if (isset($parts['text'])) {
                            $chat->messages()->create([
                                'role' => 'assistant',
                                'parts' => [
                                    'text' => $parts['text'],
                                ],
                                'attachments' => '[]',
                            ]);
                        }
                        if (isset($parts['image'])) {
                            $chat->messages()->create([
                                'role' => 'assistant',
                                'parts' => [
                                    'image' => $parts['image'],
                                ],
                                'attachments' => '[]',
                            ]);
                        }
                        $chat->touch();
                    }
                }
            } else {
                try {
                    $response = Prism::text()
                        ->withSystemPrompt(view('prompts.system'))
                        ->using($model->getProvider(), $model->value)
                        ->withMessages($messages)
                        ->asStream();

                    foreach ($response as $chunk) {
                        $chunkData = [
                            'chunkType' => $chunk->chunkType->value,
                            'content' => $chunk->text,
                        ];

                        if (! isset($parts[$chunk->chunkType->value])) {
                            $parts[$chunk->chunkType->value] = '';
                        }

                        $parts[$chunk->chunkType->value] .= $chunk->text;

                        yield json_encode($chunkData) . "\n";
                    }

                    if ($parts !== []) {
                        $chat->messages()->create([
                            'role' => 'assistant',
                            'parts' => $parts,
                            'attachments' => '[]',
                        ]);
                        $chat->touch();
                    }
                } catch (Throwable $throwable) {
                    Log::error("Chat stream error for chat {$chat->id}: " . $throwable->getMessage());
                    yield json_encode([
                        'chunkType' => 'error',
                        'content' => 'Stream failed',
                    ]) . "\n";
                }
            }
        });
    }

    private function buildConversationHistory(Chat $chat): array
    {
        return $chat->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn(Message $message): UserMessage|AssistantMessage => match ($message->role) {
                'user' => new UserMessage(content: $message->parts['text'] ?? ''),
                'assistant' => new AssistantMessage(content: $message->parts['text'] ?? ''),
            })
            ->toArray();
    }
}
