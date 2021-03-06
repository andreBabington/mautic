<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\WebhookBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI Command to process queued webhook payloads.
 */
class ProcessWebhookQueuesCommand extends ContainerAwareCommand
{
    /** @var \Mautic\CoreBundle\Factory\MauticFactory $factory */
    protected $factory;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('mautic:webhooks:process')
            ->setDescription('Process queued webhook payloads')
            ->addOption(
                '--webhook-id',
                '-i',
                InputOption::VALUE_OPTIONAL,
                'Process payload for a specific webhook.  If not specified, all webhooks will be processed.',
                null
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->factory = $this->getContainer()->get('mautic.factory');

        $queueMode = $this->factory->getParameter('queue_mode');

        // check to make sure we are in queue mode
        if ($queueMode != 'command_process') {
            $output->writeLn('Webhook Bundle is in immediate process mode. To use the command function change to command mode.');

            return 0;
        }

        $id = $input->getOption('webhook-id');

        /** @var \Mautic\WebhookBundle\Model\WebhookModel $model */
        $model = $this->factory->getModel('webhook');

        if ($id) {
            $webhook  = $model->getEntity($id);
            $webhooks = ($webhook !== null && $webhook->isPublished()) ? [$id => $webhook] : [];
        } else {
            // make sure we only get published webhook entities
            $webhooks = $model->getEntities(
                [
                    'filter' => [
                        'force' => [
                            [
                                'column' => 'e.isPublished',
                                'expr'   => 'eq',
                                'value'  => 1,
                            ],
                        ],
                    ],
                ]
            );
        }

        if (!count($webhooks)) {
            $output->writeln('<error>No published webhooks found. Try again later.</error>');

            return;
        }

        $output->writeLn('<info>Processing Webhooks</info>');

        try {
            $model->processWebhooks($webhooks);
        } catch (\Exception $e) {
            $output->writeLn('<error>'.$e->getMessage().'</error>');
        }
        $output->writeLn('<info>Webhook Processing Complete</info>');
    }
}
