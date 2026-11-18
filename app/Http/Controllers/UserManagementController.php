<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Admin can see all users, or we can filter to just Owner and Kasir
        // The request says "management user" to create credentials for Kasir and Owner
        $users = User::all();
        $active = 'users'; // Set active menu
        $title = 'Manajemen User';
        return view('user.index', compact('users', 'active', 'title'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $active = 'users';
        $title = 'Tambah User';
        $workShifts = WorkShift::all();
        return view('user.create', compact('active', 'title', 'workShifts'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|in:owner,kasir,admin',
            'shift' => 'nullable|required_if:role,kasir|string|max:255',
            'work_shift_id' => 'nullable|required_if:role,kasir|exists:work_shifts,id',
            'is_fnb_staff' => 'nullable|boolean',
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'shift' => $validated['role'] === 'kasir' ? $validated['shift'] : null,
            'work_shift_id' => $validated['role'] === 'kasir' ? $validated['work_shift_id'] : null,
            'is_fnb_staff' => $validated['role'] === 'kasir' && $request->boolean('is_fnb_staff'),
        ]);

        return redirect()->route('users.index')->with('success', 'User berhasil ditambahkan');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $user = User::findOrFail($id);
        $active = 'users';
        $title = 'Edit User';
        $workShifts = WorkShift::all();
        return view('user.edit', compact('user', 'active', 'title', 'workShifts'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:6|confirmed',
            'role' => 'required|in:owner,kasir,admin',
            'shift' => 'nullable|required_if:role,kasir|string|max:255',
            'work_shift_id' => 'nullable|required_if:role,kasir|exists:work_shifts,id',
            'is_fnb_staff' => 'nullable|boolean',
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'shift' => $validated['role'] === 'kasir' ? $validated['shift'] : null,
            'work_shift_id' => $validated['role'] === 'kasir' ? $validated['work_shift_id'] : null,
            'is_fnb_staff' => $validated['role'] === 'kasir' && $request->boolean('is_fnb_staff'),
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($validated['password']);
        }

        $user->update($data);

        return redirect()->route('users.index')->with('success', 'User berhasil diperbarui');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);
        
        // Prevent deleting self
        if ($user->id === auth()->id()) {
            return redirect()->route('users.index')->with('error', 'Anda tidak dapat menghapus akun Anda sendiri.');
        }

        // Check if user has related invoices or transactions
        if ($user->invoices()->exists() || $user->transaction()->exists()) {
            return redirect()->route('users.index')->with('error', 'User tidak dapat dihapus karena masih memiliki riwayat transaksi atau invoice.');
        }

        try {
            $user->delete();
        } catch (QueryException $e) {
            return redirect()->route('users.index')->with('error', 'User tidak dapat dihapus karena masih terhubung dengan data lain di sistem.');
        }

        return redirect()->route('users.index')->with('success', 'User berhasil dihapus');
    }

    public function resetPassword($id)
    {
        $user = User::findOrFail($id);
        
        // Prevent resetting self if needed, but not strictly required. 
        // Admin might want to reset their own password to default? 
        // Let's allow it or blocking it? 
        // Prompt implies "admin bisa mereset (password yang sudah diganti kembali ke default)" for others usually.
        // Let's just do it.

        $user->update([
            'password' => Hash::make('password')
        ]);

        return redirect()->route('users.index')->with('success', 'Password user berhasil direset ke default (password).');
    }
}
