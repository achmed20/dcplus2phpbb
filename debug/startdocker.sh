#!/bin/bash
docker rm -f mysql
docker run -d \
-p 3307:3306 \
-e MYSQL_USER=admin \
-e MYSQL_PASS=admin \
-e ON_CREATE_DB=dcold \
--restart=always \
--name="mysql" \
tutum/mysql
