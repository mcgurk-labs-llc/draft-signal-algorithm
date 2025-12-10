#!/bin/bash

for file in $(find src/ -name "*.php"); do
	php -l "$file" || exit 1
done
