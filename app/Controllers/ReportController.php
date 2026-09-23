<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Log;
use App\Services\ReportService;
use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Throwable;

final class ReportController extends Controller
{
    public function download(string $type, string $format): void
    {
        $this->auth->requireRole('admin');

        try {
            $report = (new ReportService($this->db))->make($type);
            if ($format === 'xlsx') {
                $this->excel($report, $type);
            } elseif ($format === 'pdf') {
                $this->pdf($report, $type);
            } else {
                throw new \InvalidArgumentException('Nepoznat format.');
            }
            $this->auth->log($this->auth->id(), 'Preuzet izveštaj: ' . $type . '.' . $format);
        } catch (Throwable $e) {
            (new Log($this->db))->deliveryFailure(
                'report:' . $type, $this->auth->user()['email'], $e->getMessage()
            );
            throw $e;
        }
    }

    private function excel(array $report, string $type): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Izveštaj');
        $sheet->fromArray($report['headers'], null, 'A1');
        $sheet->fromArray(array_map('array_values', $report['rows']), null, 'A2');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $type . '.xlsx"');
        (new Xlsx($spreadsheet))->save('php://output');
    }

    private function pdf(array $report, string $type): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $html = $twig->render('reports/table.twig', $report);
        $dompdf = new Dompdf(['defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->stream($type . '.pdf', ['Attachment' => true]);
    }
}
