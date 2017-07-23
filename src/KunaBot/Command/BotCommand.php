<?php

namespace madmis\KunaBot\Command;

use madmis\KunaApi\Http;
use madmis\KunaApi\KunaApi;
use madmis\KunaApi\Model\MyAccount;
use madmis\KunaApi\Model\Ticker;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Formatter\OutputFormatterStyleStack;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class BotCommand
 * @package madmis\KunaBot\Command
 */
class BotCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('bot:run')
            ->setDescription('Run bot')
            ->addArgument('pair', InputArgument::REQUIRED, 'Trade pair');
//            ->
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->getFormatter()->setStyle('red', new OutputFormatterStyle('red', null));
        $output->getFormatter()->setStyle('green', new OutputFormatterStyle('green', null));
        $output->getFormatter()->setStyle('yellow', new OutputFormatterStyle('yellow', null));
        $output->getFormatter()->setStyle('blue', new OutputFormatterStyle('blue', null));
        $output->getFormatter()->setStyle('magenta', new OutputFormatterStyle('magenta', null));
        $output->getFormatter()->setStyle('cyan', new OutputFormatterStyle('cyan', null));
        $output->getFormatter()->setStyle('white', new OutputFormatterStyle('white', null));

        $output->writeln('<red>foo</red>');
        $output->writeln('<green>foo</green>');
        $output->writeln('<yellow>foo</yellow>');
        $output->writeln('<blue>foo</blue>');
        $output->writeln('<magenta>foo</magenta>');
        $output->writeln('<cyan>foo</cyan>');
        $output->writeln('<white>foo</white>');
        return;


        $pair = $input->getArgument('pair');
        [$base, $quote] = str_split($pair, 3);

        while (true) {
            $output->writeln("<info>***************{$base}/{$quote}***************</info>");

            /** @var KunaApi $kuna */
            $kuna = $this->getContainer()->get('kuna.client');

            // check pair balance. If we have base currency sell it
            $accounts = $kuna->signed()->me(true)->getAccounts();
            $filtered = array_filter($accounts, function(MyAccount $account) use ($base) {
                return $account->getCurrency() === $base;
            });

            if ($filtered) {
                /** @var MyAccount $account */
                $account = reset($filtered);

                $funds = $account->getBalance() - $account->getLocked();

                if ($funds > 0) {
                    $output->writeln("Create sell order with amount: {$funds} {$base}.");
                } else {
                    $output->writeln("<error>There no funds to create Sell order.</error>");
                }
            } else {
                $output->writeln("<error>Can not find account for {$base} currency</error>");
            }

            return;


            /** @var Ticker $ticker */
            $ticker = $kuna->shared()->tickers(Http::PAIR_ETHUAH, true);
            var_dump($ticker);

            $output->writeln(sprintf(
                "<comment>Memory usage: %s Mb</comment>",
                memory_get_usage(true) / 1024 / 1024
            ));

            sleep(10);
        }
    }
}
