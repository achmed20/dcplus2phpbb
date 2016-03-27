this script "TRIES" to convert an old DC+ forum into phpbb 3.1.+

notes
-----

please only import into a completly blank version of phpbb. just install (including it's setup) phpbb and then leave it alone

- All forums will be imported as flat hirachy
- no attachments supported
- BB lang ([link], ...) is not converted yet
- private messages missing
- user passwords wont be converted and so users musr request new password.
- probably plenty more


Usage
-----
./dcconvert.php [SOURCE DSN] [TARGET DSN]

start like this!
	./dcconvert.php \
		mysql://admin:admin@192.168.12.111:3306/dc2 \
		mysql://admin:admin@192.168.12.111:3306/phpbb