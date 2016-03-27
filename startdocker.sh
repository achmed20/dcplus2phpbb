#!/bin/bash
docker rm -f dc-mysql
docker run -d \
-p 3307:3306 \
-e MYSQL_USER=admin \
-e MYSQL_PASS=admin \
-e ON_CREATE_DB=dcold \
-e STARTUP_SQL="/data/sql/import.sql" \
-v /home/achmed/dcplus2phpbb:/data \
--restart=always \
--name="dc-mysql" \
tutum/mysql
