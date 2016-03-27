this script "TRIES" to convert an old DC+ forum into phpbb 3.1.+

also, this script wont write a single bit into the old DC+ Database so you can use the original database as source DSN if you like

Prerequisits
------------
Only import into a completly blank version of phpbb. just install (finish the setup) phpbb and then leave it alone.


Usage
-----

        ./dcconvert.php [SOURCE DSN] [TARGET DSN]

start like this:


        ./dcconvert.php mysql://admin:admin@192.168.12.111:3306/dc2 mysql://admin:admin@192.168.12.111:3306/phpbb

After the conversion you need to set the propper rights for each forum (probably just copy them from the default forum) and refresh each forum. Otherwhise it wont phpBB wont show you any post, counts, answers etc. refresh migh need to be done multiple times

notes
-----

- All forums will be imported as flat hirachy
- no attachments supported
- BB lang ([link], ...) is not converted yet
- private messages missing
- user passwords wont be converted and so users must request new password.
- probably plenty more
