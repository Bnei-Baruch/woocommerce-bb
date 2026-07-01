zip:
	cd .. && zip -r woocommerce-bb/woocommerce-bb.zip woocommerce-bb \
		--exclude "woocommerce-bb/.git/*" \
		--exclude "woocommerce-bb/.gitignore" \
		--exclude "woocommerce-bb/Makefile" \
		--exclude "woocommerce-bb/woocommerce-bb.zip"

.PHONY: zip
