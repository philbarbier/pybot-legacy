Supervisor configuration

	cp pybot.example.conf pybot.conf
	vim pybot.conf # Change user, group and paths

	sudo apt-get install supervisor
	sudo /etc/init.d/supervisor start
	sudo cp ./pybot.conf /etc/supervisor/conf.d/
	sudo supervisorctl reread
	sudo supervisorctl reload
	sudo supervisorctl status


