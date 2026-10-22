<?php

declare(strict_types=1);

namespace Modulento\Dating;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Session;

final class AdminController extends Controller
{
    public function reports(array $params): void
    {
        $app = $this->app;
        $reports = (new Reports($app->db))->listOpen();

        $this->render('@dating/admin/reports.twig', [
            'reports' => array_map(fn (array $report) => [
                'id' => (int) $report['id'],
                'reporter' => $app->accounts->findById((int) $report['reporter_account_id']),
                'reported' => $app->accounts->findById((int) $report['reported_account_id']),
                'category' => $report['category'],
                'explanation' => $report['explanation'],
                'created_at' => $report['created_at'],
            ], $reports),
        ]);
    }

    public function decide(array $params): void
    {
        $status = (string) ($_POST['status'] ?? '');
        $adminId = (int) $this->app->auth->account()['id'];
        (new Reports($this->app->db))->decide((int) $params['id'], $status, $adminId);

        Session::flash('success', $this->trans('dating.admin.decided'));
        $this->redirect('/admin/dating/reports');
    }
}
