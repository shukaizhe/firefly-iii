<?php
/**
 * NewUserControllerTest.php
 * Copyright (C) 2016 thegrumpydictator@gmail.com
 *
 * This software may be modified and distributed under the terms of the
 * Creative Commons Attribution-ShareAlike 4.0 International License.
 *
 * See the LICENSE file for details.
 */


/**
 * Generated by PHPUnit_SkeletonGenerator on 2016-12-10 at 05:51:42.
 */
class NewUserControllerTest extends TestCase
{


    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    public function setUp()
    {
        parent::setUp();
    }

    /**
     * @covers \FireflyIII\Http\Controllers\NewUserController::index
     */
    public function testIndex()
    {
        $this->be($this->emptyUser());
        $this->call('get', route('new-user.index'));
        $this->assertResponseStatus(200);
        $this->see('<ol class="breadcrumb">');
    }

    /**
     * @covers \FireflyIII\Http\Controllers\NewUserController::submit
     */
    public function testSubmit()
    {
        $data = [
            'bank_name'    => 'New bank',
            'bank_balance' => 100,
        ];
        $this->be($this->emptyUser());
        $this->call('post', route('new-user.submit'), $data);
        $this->assertResponseStatus(302);
        $this->assertSessionHas('success');
    }

}