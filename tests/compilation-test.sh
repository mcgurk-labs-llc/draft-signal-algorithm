#!/bin/bash

for file in $(find src/ -name "*.php"); do
	php -l "$file" || exit 1
done

for file in $(find tests/ -name "*.php"); do
	php -l "$file" || exit 1
done
