apt-get install ant doxygen openjdk-7-jdk
pear config-set auto_discover 1
pear install pear.phpunit.de/phploc
pear channel-discover pear.pdepend.org
pear install pdepend/PHP_Depend-beta
pear channel-discover pear.phpmd.org
pear channel-discover pear.pdepend.org
pear install --alldeps phpmd/PHP_PMD
pear install PHP_CodeSniffer-1.5.0RC2
pear config-set auto_discover 1
pear install pear.phpunit.de/phpcpd
