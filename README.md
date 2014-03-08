whatsdogetip
============

A dogecoin tip bot for WhatsApp made in PHP; not intended for use, but for modification to make your own tip bot.

Now, since whatsapp are not interested in replying to my support request, here, I've open sourced the tipbot.

Included are two of three required libraries; the other one is only needed to use it as a whatsapp tipbot, and this was not my intention for open sourcing this, given that the WhatsApp number you use for it **will** get blocked within hours if you try to use it.

Hopefully this code can be used as a base to make your own tipbot in PHP.

I have removed the dogec0in.com integration that was in the original version of this tipbot; however, you can still use "waterbowl" instead of a dogecoin address when withdrawing. I'd appreciate it if you could keep this feature in your fork, though I fully understand if you do not wish to.

Sure, some of the code could be improved, and thus, pull requests to improve this "tipbot base" will be welcomed.

The main tipbot file is dogetip.php and this is licensed under the MIT license.

Dogecoin.php is based on [bitcoin-php](https://github.com/mikegogulski/bitcoin-php), which is in the public domain; jsonRPCClient.php is part of [JSON-RPC PHP](http://jsonrpcphp.org/), which is licensed under the GPL.
