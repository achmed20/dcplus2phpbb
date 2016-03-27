stuff!

docker run -d \
-p 3307:3306 \
-e MYSQL_USER=admin \
-e MYSQL_PASS=admin \
-e ON_CREATE_DB=dcold \
-e STARTUP_SQL="/data/" \
-v /home/achmed/dcplus2phpbb:/data \
--name="mysql" \
tutum/mysql

--restart=always \

