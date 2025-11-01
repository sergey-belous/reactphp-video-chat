FROM php:8.5.0RC3-cli-alpine3.21
# RUN apk update && apk add libffi && apk add libffi-dev 
# RUN docker-php-source extract \
# 	# install dependencies \
#     && docker-php-ext-install ffi \
#     && docker-php-ext-enable ffi \
# 	# do important things \
# 	&& docker-php-source delete
# CMD [ "php", "/app/ice.php" ]