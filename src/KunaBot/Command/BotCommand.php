<?php

namespace madmis\KunaBot\Command;

use madmis\KunaApi\KunaApi;
use madmis\KunaApi\Model\History;
use madmis\KunaApi\Model\MyAccount;
use madmis\KunaApi\Model\Order;
use madmis\KunaApi\Model\Ticker;
use madmis\KunaBot\Exception\BreakIterationException;
use madmis\KunaBot\Exception\StopBotException;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class BotCommand
 * @package madmis\KunaBot\Command
 */
class BotCommand extends ContainerAwareCommand
{
    const DEF_SCALE = 10;

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    protected function configure()
    {
        $this
            ->setName('bot:run')
            ->setDescription('Run bot')
            ->addArgument('pair', InputArgument::REQUIRED, 'Trade pair')
            ->addOption('--margin', null, InputOption::VALUE_REQUIRED, 'Margin %', 5)
            ->addOption('--show-memory-usage', null, InputOption::VALUE_NONE, 'Show memory usage in the end of each iteration')
            ->addOption('--buy-price-increase-unit', null, InputOption::VALUE_REQUIRED, 'Buy price increase unit', 1);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setColors($output);
        $this->input = $input;
        $this->output = $output;

        $pair = $input->getArgument('pair');
        [$base, $quote] = $this->splitPair($pair);
        $margin = (int)$this->input->getOption('margin') / 100;
        $showMemoryUsage = $this->input->getOption('show-memory-usage');

        while (true) {
            try {
                $output->writeln("<y> ***************{$base}/{$quote}*************** </y>");

                // check active orders
                $this->checkActiveOrders($pair);

                // Check base funds and create SELL order if possible
                $this->baseFundsProcessing($pair, $margin);

                // Check quote funds and create BUY order if possible
                $this->quoteFundsProcessing($pair);
            } catch (BreakIterationException $e) {
                sleep($e->getTimeout());
                continue;
            } finally {
                if ($showMemoryUsage) {
                    $usage = memory_get_peak_usage(true) / 1024;
                    $output->writeln(sprintf('<y>Memory usage: %s Kb (%s Mb)</y>', $usage, $usage / 1024));
                }
            }

            sleep(10);
        }
    }

    /**
     * @param string $pair
     */
    protected function quoteFundsProcessing(string $pair)
    {
        [$base, $quote] = $this->splitPair($pair);
        /** @var KunaApi $kuna */
        $kuna = $this->getContainer()->get('kuna.client');
        $increaseUnit = (float)$this->input->getOption('buy-price-increase-unit');

        $quoteAccount = $this->getCurrencyAccount($quote);
        // check if we can create buy order - buy base currency
        $quoteFunds = bcsub($quoteAccount->getBalance(), $quoteAccount->getLocked(), self::DEF_SCALE);
        if (bccomp($quoteFunds, 0, self::DEF_SCALE) === 1) {
            $this->output->writeln("<g>Free quote funds: {$quoteFunds} {$quote}.</g>");
            $this->output->writeln('<g>Can create BUY order.</g>');

            // don't buy close to highest price
            /** @var Ticker $ticker */
            $ticker = $kuna->shared()->tickers($pair, true);
            $priceDiff = bcsub($ticker->getHigh(), $ticker->getLow(), self::DEF_SCALE);
            $allowedPrice = bcadd(
                bcmul($priceDiff, 0.75, self::DEF_SCALE),
                $ticker->getLow(),
                self::DEF_SCALE
            );
            $this->output->writeln("<y>Maximum allowed buy price: {$allowedPrice}</y>");
            $this->output->writeln("<y>Current buy price: {$ticker->getBuy()}</y>");
            if (bccomp($ticker->getBuy(), $allowedPrice) !== 1) {
                $this->output->writeln('<g>BUY allowed.</g>');
                // make buy price slightly more current buy price
                $buyPrice = bcadd($ticker->getBuy(), $increaseUnit, self::DEF_SCALE);
                $buyVolume = bcdiv($quoteFunds, $buyPrice, self::DEF_SCALE);
                $this->output->writeln("\t<w>Buy price: {$buyPrice}</w>");
                $this->output->writeln("\t<w>Buy volume: {$buyVolume}</w>");

                $this->output->writeln('<g>Create BUY order.</g>');
                $order = $kuna->signed()->createBuyOrder($pair, $buyVolume, $buyPrice, true);
                $this->output->writeln('<g>BUY order created.</g>');
                $this->output->writeln("\t<w>Id: {$order->getId()}</w>");
                $this->output->writeln("\t<w>Type: {$order->getOrdType()}</w>");
                $this->output->writeln("\t<w>Price: {$order->getPrice()}</w>");
                $this->output->writeln("\t<w>Side: {$order->getSide()}</w>");
                $this->output->writeln("\t<w>State: {$order->getState()}</w>");
                $this->output->writeln("\t<w>Volume: {$order->getVolume()}</w>");
            }
        }

    }

    /**
     * @param string $pair
     * @param float $margin
     * @throws StopBotException
     */
    protected function baseFundsProcessing(string $pair, float $margin)
    {
        [$base, $quote] = $this->splitPair($pair);
        /** @var KunaApi $kuna */
        $kuna = $this->getContainer()->get('kuna.client');

        // check available funds
        $baseAccount = $this->getCurrencyAccount($base);
        // check if we can create sell order - sell base currency
        $baseFunds = bcsub($baseAccount->getBalance(), $baseAccount->getLocked(), self::DEF_SCALE);
        if (bccomp($baseFunds, 0, self::DEF_SCALE) === 1) {
            $this->output->writeln("<g>Free base funds: {$baseFunds} {$base}.</g>");
            $this->output->writeln('<g>Can create sell order.</g>');

            // find last buy price
            /** @var History[] $history */
            $history = $kuna->signed()->myHistory($pair, true);
            /** @var History $latestBuy */
            $latestBuy = null;
            foreach ($history as $item) {
                if ($item->getSide() === 'bid') {
                    $latestBuy = $item;
                    break;
                }
            }

            if (!$latestBuy) {
                $this->output->writeln('<r>Can not define sell price (no closed buy orders available for this pair).</r>');
                $this->output->writeln('<y>Try to place sell order with properly sell price manually.</y>');

                throw new StopBotException('Stop bot');
            }

            // we have base currency funds and last buy order. So we can calculate sell price.
            $price = bcadd(
                bcmul($latestBuy->getPrice(), $margin, self::DEF_SCALE),
                $latestBuy->getPrice(),
                self::DEF_SCALE
            );
            $this->output->writeln("\t<w>Sell price: {$price} {$quote}</w>");
            $receive = bcmul($baseFunds, $price, self::DEF_SCALE);
            $this->output->writeln("\t<w>Will receive: {$receive} {$quote}</w>");

            $this->output->writeln('<g>Create SELL order.</g>');
            $order = $kuna->signed()->createSellOrder($pair, $baseFunds, $price, true);
            $this->output->writeln('<g>SELL order created.</g>');
            $this->output->writeln("\t<w>Id: {$order->getId()}</w>");
            $this->output->writeln("\t<w>Type: {$order->getOrdType()}</w>");
            $this->output->writeln("\t<w>Price: {$order->getPrice()}</w>");
            $this->output->writeln("\t<w>Side: {$order->getSide()}</w>");
            $this->output->writeln("\t<w>State: {$order->getState()}</w>");
            $this->output->writeln("\t<w>Volume: {$order->getVolume()}</w>");
        }
    }


    /**
     * @param OutputInterface $output
     */
    protected function setColors(OutputInterface $output)
    {
        $output->getFormatter()->setStyle('r', new OutputFormatterStyle('red', null));
        $output->getFormatter()->setStyle('g', new OutputFormatterStyle('green', null));
        $output->getFormatter()->setStyle('y', new OutputFormatterStyle('yellow', null));
        $output->getFormatter()->setStyle('b', new OutputFormatterStyle('blue', null));
        $output->getFormatter()->setStyle('m', new OutputFormatterStyle('magenta', null));
        $output->getFormatter()->setStyle('c', new OutputFormatterStyle('cyan', null));
        $output->getFormatter()->setStyle('w', new OutputFormatterStyle('white', null));
    }

    /**
     * Check opened orders. If we have any opened orders, don't do nothing
     * Wait until it's will be executed or closed (can be closed only manually)
     * @param string $pair
     * @throws BreakIterationException
     */
    protected function checkActiveOrders($pair)
    {
        /** @var KunaApi $kuna */
        $kuna = $this->getContainer()->get('kuna.client');
        /** @var Order[] $activeOrders */
        $activeOrders = $kuna->signed()->activeOrders($pair, true);
        if ($activeOrders) {
            $this->output->writeln(sprintf(
                '<y>Current pair %s has active orders: %s</y>',
                $pair,
                count($activeOrders)
            ));
            foreach ($activeOrders as $order) {
                $this->output->writeln("\t<w>Order id: {$order->getId()}</w>");
                $this->output->writeln("\t\t<w>Type: {$order->getOrdType()}</w>");
                $this->output->writeln("\t\t<w>Side: {$order->getSide()}</w>");
                $this->output->writeln("\t\t<w>State: {$order->getState()}</w>");
            }
            $this->output->writeln('<y>Wait until active orders will be executed or closed.</y>');

            $e = new BreakIterationException('Wait until active orders will be executed or closed.');
            $e->setTimeout(30);

            throw $e;
        }
    }

    /**
     * @param string $currency
     * @return MyAccount
     * @throws \LogicException
     */
    protected function getCurrencyAccount(string $currency): MyAccount
    {
        /** @var KunaApi $kuna */
        $kuna = $this->getContainer()->get('kuna.client');

        // check pair balance. If we have base currency sell it
        $accounts = $kuna->signed()->me(true)->getAccounts();
        $filtered = array_filter($accounts, function (MyAccount $account) use ($currency) {
            return $account->getCurrency() === $currency;
        });

        if (!$filtered) {
            throw new \LogicException("Can't find account for currency: {$currency}");
        }

        return reset($filtered);
    }

    /**
     * @param string $pair
     * @return array
     */
    protected function splitPair(string $pair): array
    {
        return str_split($pair, 3);
    }
}
