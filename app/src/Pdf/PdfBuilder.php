<?php

declare(strict_types=1);

namespace App\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

final readonly class PdfBuilder
{
    public function __construct(private Environment $twig)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function build(string $template, array $context): string
    {
        $html = $this->twig->render($template, $context);

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
