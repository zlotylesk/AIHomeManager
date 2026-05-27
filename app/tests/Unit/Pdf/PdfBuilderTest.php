<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pdf;

use App\Pdf\PdfBuilder;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

final class PdfBuilderTest extends TestCase
{
    public function testBuildRendersValidPdfWithMagicBytes(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('exports/test.html.twig', ['rows' => []])
            ->willReturn('<html><body><h1>Test</h1></body></html>');

        $builder = new PdfBuilder($twig);
        $pdf = $builder->build('exports/test.html.twig', ['rows' => []]);

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testBuildPropagatesTwigExceptionOnMissingTemplate(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')
            ->willThrowException(new \Twig\Error\LoaderError('Template not found'));

        $builder = new PdfBuilder($twig);

        $this->expectException(\Twig\Error\LoaderError::class);
        $builder->build('exports/nonexistent.html.twig', []);
    }
}
