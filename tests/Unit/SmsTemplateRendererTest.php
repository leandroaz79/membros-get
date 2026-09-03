<?php

namespace Tests\Unit;

use App\Support\SmsTemplateRenderer;
use PHPUnit\Framework\TestCase;

class SmsTemplateRendererTest extends TestCase
{
    public function test_replaces_placeholders(): void
    {
        $message = SmsTemplateRenderer::render(
            'Ola {nome_cliente}! {nome_produto}: {link_acesso}',
            [
                '{nome_cliente}' => 'Maria',
                '{nome_produto}' => 'Curso X',
                '{link_acesso}' => 'https://exemplo.com/acesso',
            ]
        );

        $this->assertSame(
            'Ola Maria! Curso X: https://exemplo.com/acesso',
            $message
        );
    }

    public function test_prepare_for_send_rejects_over_160_characters(): void
    {
        $template = str_repeat('a', 150).'{nome_cliente}';

        $prepared = SmsTemplateRenderer::prepareForSend($template, [
            '{nome_cliente}' => str_repeat('b', 20),
        ]);

        $this->assertFalse($prepared['ok']);
        $this->assertGreaterThan(SmsTemplateRenderer::MAX_LENGTH, $prepared['length']);
    }

    public function test_prepare_for_send_accepts_valid_message(): void
    {
        $prepared = SmsTemplateRenderer::prepareForSend('PIX {valor}: {link_pix}', [
            '{valor}' => 'R$ 10,00',
            '{link_pix}' => 'https://exemplo.com/pix',
        ]);

        $this->assertTrue($prepared['ok']);
        $this->assertLessThanOrEqual(SmsTemplateRenderer::MAX_LENGTH, $prepared['length']);
        $this->assertStringContainsString('R$ 10,00', $prepared['message']);
    }
}
