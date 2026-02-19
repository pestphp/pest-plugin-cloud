<?php

it('has cloud plugin registered', function () {
    expect(class_exists(Pest\PestCloud\Plugin::class))->toBeTrue();
});
