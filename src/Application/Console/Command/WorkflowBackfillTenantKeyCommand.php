<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\OrmManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills workflow_instances.tenant_key for instances started before the
 * column existed (they got ''). Run it after orm:sync adds the column and
 * before orm:sync --allow-destructive drops the old tenant-less unique
 * index; until then two tenants still cannot share a subject id. Safe to
 * run again.
 */
#[AsCommand(name: 'workflow:backfill-tenant-key', description: 'Fill workflow_instances.tenant_key from tenant_id for instances started before it existed')]
final class WorkflowBackfillTenantKeyCommand extends Command
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    protected function configure(): void
    {
        $this->setName('workflow:backfill-tenant-key')
             ->setDescription('Fill workflow_instances.tenant_key from tenant_id for instances started before it existed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $updated = $this->orm->getAdapter()->execute(
            "UPDATE workflow_instances SET tenant_key = tenant_id WHERE tenant_id IS NOT NULL AND tenant_key <> tenant_id",
        )->rowCount;
        $io->success("Filled tenant_key on {$updated} workflow instance(s).");

        return Command::SUCCESS;
    }
}
