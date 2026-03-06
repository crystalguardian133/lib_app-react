<?php
namespace App\Http\Controllers;

use App\Models\Member;

class MemberCardController extends Controller
{
    public function show(Member $member)
    {
        return view('cards.index', [   // 👈 matches your file location
            'member' => $member,
            'bgPath' => asset('img/card_bg.png'),
        ]);
    }
}
