<?php
/**
 * The invoice PDF (loaded by render_invoice_pdf() in invoices.php only when needed).
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

// ---------------------------------------------------------------------------
// The PDF (A4, letterhead: logo and company name at the top, paw trail behind,
// studio details at the bottom)
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../tFPDF/font/unifont/ttfonts.php';
require_once __DIR__ . '/../tFPDF/tfpdf.php';

class PawpadInvoicePdf extends tFPDF
{
    public function Header(): void
    {
        $dir = __DIR__ . '/';
        $this->Image($dir . 'paw-trail.png', 0, 0, 210, 297);
        $this->Image($dir . 'logo.png', 18, 13, 56);
        $this->SetXY(100, 15);
        $this->SetFont('Carlito', 'B', 13);
        $this->SetTextColor(0x2D, 0x3B, 0x36);
        $this->Cell(92, 6, INVOICE_COMPANY, 0, 2, 'R');
        $this->SetFont('Carlito', '', 9.5);
        $this->SetTextColor(0xB1, 0x8D, 0x4E);
        $this->Cell(92, 5, INVOICE_TAGLINE, 0, 2, 'R');
        $this->SetFont('Carlito', '', 8.5);
        $this->SetTextColor(0x6B, 0x6B, 0x6B);
        $this->Cell(92, 4.5, INVOICE_SINCE, 0, 2, 'R');
        $this->SetDrawColor(0xB1, 0x8D, 0x4E);
        $this->SetLineWidth(0.4);
        $this->Line(18, 36, 192, 36);
        $this->SetY(44);
    }

    public function Footer(): void
    {
        $this->SetY(-26);
        $this->SetDrawColor(0xB1, 0x8D, 0x4E);
        $this->SetLineWidth(0.3);
        $this->Line(18, $this->GetY(), 192, $this->GetY());
        $this->Ln(2.5);
        $this->SetFont('Carlito', '', 8);
        $this->SetTextColor(0x6B, 0x6B, 0x6B);
        $this->Cell(0, 4, 'Studio: ' . INVOICE_STUDIO_ADDRESS, 0, 1, 'C');
        $this->Cell(0, 4, implode('   •   ', INVOICE_CONTACT), 0, 1, 'C');
        $this->SetFont('Carlito', '', 7.5);
        $this->SetTextColor(0x2D, 0x3B, 0x36);
        $this->Cell(0, 4, 'CIN: ' . INVOICE_CIN, 0, 1, 'C');
        if ($this->PageNo() > 1) {
            $this->SetTextColor(0x9A, 0x9A, 0x9A);
            $this->Cell(0, 3.5, 'Page ' . $this->PageNo(), 0, 0, 'R');
        }
    }
}

function build_invoice_pdf(array $invoice): string
{
    $pdf = new PawpadInvoicePdf('P', 'mm', 'A4');
    $pdf->AddFont('Carlito', '', 'Carlito-Regular.ttf', true);
    $pdf->AddFont('Carlito', 'B', 'Carlito-Bold.ttf', true);
    $pdf->SetMargins(18, 44, 18);
    $pdf->SetAutoPageBreak(true, 32);
    $pdf->SetTitle('Pawpad invoice ' . $invoice['number'], true);
    $pdf->SetAuthor(INVOICE_COMPANY, true);
    $pdf->SetCreator('Pawpad admin panel', true);
    $pdf->AddPage();
    $ink = [0x2D, 0x3B, 0x36];
    $muted = [0x6B, 0x6B, 0x6B];
    $gold = [0xB1, 0x8D, 0x4E];
    $color = function (array $c) use ($pdf): void {
        $pdf->SetTextColor($c[0], $c[1], $c[2]);
    };
    $date = parse_studio_date($invoice['date']);
    $revised = $invoice['revision'] > 1;

    // Title and invoice details
    $top = $pdf->GetY();
    $pdf->SetFont('Carlito', 'B', 22);
    $color($ink);
    $pdf->Cell(90, 10, 'INVOICE', 0, 2);
    if ($revised) {
        $pdf->SetFont('Carlito', '', 9);
        $color($gold);
        $pdf->Cell(90, 5, 'Revised ' . (new DateTimeImmutable($invoice['updatedAt'] ?? 'now'))->setTimezone(studio_tz())->format('j F Y'), 0, 2);
    }
    $pdf->SetXY(112, $top + 1);
    $details = [['Invoice no.', $invoice['number']], ['Date', $date ? $date->format('j F Y') : $invoice['date']]];
    if ($invoice['bookingRef'] !== '') {
        $details[] = ['Booking', $invoice['bookingRef']];
    }
    foreach ($details as [$label, $value]) {
        $pdf->SetX(112);
        $pdf->SetFont('Carlito', '', 9.5);
        $color($muted);
        $pdf->Cell(28, 5.5, $label, 0, 0, 'R');
        $pdf->SetFont('Carlito', 'B', 10);
        $color($ink);
        $pdf->Cell(52, 5.5, $value, 0, 1, 'R');
    }

    // Billed to, and payment
    $pdf->SetY(max($pdf->GetY(), $top + 20) + 4);
    $y = $pdf->GetY();
    $pdf->SetFont('Carlito', 'B', 8.5);
    $color($gold);
    $pdf->Cell(100, 5, 'BILLED TO', 0, 2);
    $pdf->SetFont('Carlito', 'B', 11.5);
    $color($ink);
    $pdf->Cell(100, 6, $invoice['customer']['name'], 0, 2);
    $pdf->SetFont('Carlito', '', 10);
    $color($muted);
    foreach ([$invoice['customer']['phone'], $invoice['customer']['email']] as $line) {
        if ($line !== '') {
            $pdf->Cell(100, 5, $line, 0, 2);
        }
    }
    $afterCustomer = $pdf->GetY();
    $pdf->SetXY(122, $y);
    $pdf->SetFont('Carlito', 'B', 8.5);
    $color($gold);
    $pdf->Cell(70, 5, 'PAYMENT', 0, 2, 'R');
    $pdf->SetFont('Carlito', '', 10);
    $color($ink);
    $pdf->MultiCell(70, 5, $invoice['balance'] > 0 ? 'Amount due: ' . inr($invoice['balance']) : ($invoice['payment'] !== '' ? $invoice['payment'] : 'Paid'), 0, 'R');
    $pdf->SetY(max($afterCustomer, $pdf->GetY()) + 7);

    // Items
    $pdf->SetFillColor(0xF3, 0xEE, 0xE4);
    $pdf->SetFont('Carlito', 'B', 9.5);
    $color($ink);
    $pdf->Cell(10, 8, '#', 0, 0, 'C', true);
    $pdf->Cell(122, 8, 'Description', 0, 0, 'L', true);
    $pdf->Cell(42, 8, 'Amount', 0, 1, 'R', true);
    $pdf->SetDrawColor(0xE2, 0xDA, 0xCB);
    $pdf->SetLineWidth(0.2);
    foreach ($invoice['lines'] as $i => $line) {
        if ($pdf->GetY() > 245) {
            $pdf->AddPage();
        }
        $rowTop = $pdf->GetY() + 2;
        $pdf->SetXY(18, $rowTop);
        $pdf->SetFont('Carlito', '', 10);
        $color($muted);
        $pdf->Cell(10, 5.5, (string) ($i + 1), 0, 0, 'C');
        $pdf->SetXY(28, $rowTop);
        $pdf->SetFont('Carlito', 'B', 10.5);
        $color($ink);
        $pdf->MultiCell(122, 5.5, $line['description'], 0, 'L');
        if (($line['detail'] ?? '') !== '') {
            $pdf->SetX(28);
            $pdf->SetFont('Carlito', '', 9);
            $color($muted);
            $pdf->MultiCell(122, 4.5, $line['detail'], 0, 'L');
        }
        $rowBottom = $pdf->GetY() + 2;
        $pdf->SetXY(150, $rowTop);
        $pdf->SetFont('Carlito', '', 10.5);
        $color($ink);
        $pdf->Cell(42, 5.5, inr((float) $line['amount']), 0, 0, 'R');
        $pdf->SetY($rowBottom);
        $pdf->Line(18, $rowBottom, 192, $rowBottom);
    }

    // Totals
    $pdf->Ln(3);
    $total = function (string $label, string $value, bool $strong = false) use ($pdf, $color, $ink, $muted): void {
        $pdf->SetX(112);
        $pdf->SetFont('Carlito', $strong ? 'B' : '', $strong ? 12 : 10);
        $color($strong ? $ink : $muted);
        $pdf->Cell(38, $strong ? 8 : 6, $label, 0, 0, 'R');
        $color($ink);
        $pdf->Cell(42, $strong ? 8 : 6, $value, 0, 1, 'R');
    };
    if ($invoice['deposit'] > 0 || count($invoice['lines']) > 1) {
        $total('Subtotal', inr($invoice['subtotal']));
    }
    if ($invoice['deposit'] > 0) {
        $total('Less: booking deposit paid', '-' . inr($invoice['deposit']));
    }
    $total('Total', inr($invoice['total']), true);
    if ($invoice['paid'] > 0) {
        $total('Paid', inr($invoice['paid']));
    }
    if ($invoice['balance'] > 0) {
        $total('Balance due', inr($invoice['balance']), true);
    }

    // Thank you
    $pdf->Ln(10);
    $pdf->SetFont('Carlito', '', 10);
    $color($muted);
    $pdf->MultiCell(0, 5, "Thank you for trusting Pawpad with your pet's care.\nFor any questions, WhatsApp us on " . STUDIO_WHATSAPP . '.', 0, 'L');

    return $pdf->Output('S');
}
