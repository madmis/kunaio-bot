# Kuna.io PHP Trading Bot

Allows to trade on the exchange **kuna.io** in automatic mode.

**Warning** Use it with care and remember - you can lose your money.

This is experimental bot with very simple strategy.
You can run only one bot instance for kuna.io account.
Bot always trade with full account funds.




## Install

```bash
    $ git clone https://github.com/madmis/kunaio-bot.git ./bot
    $ cd ./bot
    $ composer install
    $ cp ./config/config.yml.dist ./config/config.yml
```

Define configuration parameters:
- **public.key** - kuna.io api public key
- **secret.key** - kuna.io api secret key

## Run bot

```bash
    $ php console.php bot:run btcuah
```

run with additional parameters:

```bash
    $ php console.php bot:run btcuah --margin=2 --buy-price-increase-unit=0.1 --show-memory-usage
```

- **margin** - margin for sell order. Calculated from last buy order price. 
- **buy-price-increase-unit** - increase unit for highest buy price.
  With this margin order will be placed first in the Buy List.
- **show-memory-usage** - show bot memory usage with each iteration 