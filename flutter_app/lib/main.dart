import 'dart:convert';
import 'dart:async';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:intl/intl.dart';

void main() => runApp(const JssApp());

class JssApp extends StatelessWidget {
  const JssApp({super.key});
  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'Batighor School Management',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: Colors.indigo),
        useMaterial3: true,
        fontFamily: 'Segoe UI',
      ),
      home: const SplashGate(),
    );
  }
}

/// Splash gate: shows Splash.gif then routes to Login or Dashboard
class SplashGate extends StatefulWidget {
  const SplashGate({super.key});
  @override
  State<SplashGate> createState() => _SplashGateState();
}

class _SplashGateState extends State<SplashGate> {
  bool _ready = false;
  String? _token;
  String? _userName;
  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    final prefs = await SharedPreferences.getInstance();
    _token = prefs.getString('token');
    _userName = prefs.getString('user_name');
    await Future.delayed(const Duration(milliseconds: 900));
    if (!mounted) return;
    setState(() => _ready = true);
  }

  @override
  Widget build(BuildContext context) {
    if (!_ready) {
      return Scaffold(
        backgroundColor: Colors.white,
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox(
                  height: 160,
                  width: 160,
                  child: Image.asset('assets/images/Splash.gif',
                      fit: BoxFit.contain)),
              const SizedBox(height: 20),
              const Text('Batighor School Management',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
            ],
          ),
        ),
      );
    }
    return _token == null
        ? const LoginScreen()
        : DashboardScreen(token: _token!, userName: _userName);
  }
}

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _userCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  bool _busy = false;
  String? _error;
  bool _passObscure = true;
  static const baseUrl =
      'https://jss.batighorbd.com/api'; // TODO: replace for production

  Future<void> _login() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final url = Uri.parse('$baseUrl/auth/login.php');
      final resp = await http.post(url,
          headers: {'Content-Type': 'application/json'},
          body: jsonEncode(
              {'username': _userCtrl.text.trim(), 'password': _passCtrl.text}));
      final data = jsonDecode(resp.body);
      if (resp.statusCode == 200 && data['success'] == true) {
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString('token', data['data']['token']);
        final user = data['data']['user'] ?? {};
        final userName = (user['name']?.toString().isNotEmpty == true)
            ? user['name'].toString()
            : user['username']?.toString() ?? '';
        await prefs.setString('user_name', userName);
        await prefs.setString(
            'user_username', user['username']?.toString() ?? '');
        await prefs.setString('user_id', user['id']?.toString() ?? '');
        final role = user['role']?.toString() ?? '';
        // Enforce teacher-only login
        if (role.toLowerCase() != 'teacher') {
          setState(() {
            _busy = false;
            _error = 'শুধুমাত্র শিক্ষক লগইন করতে পারবেন';
          });
          return;
        }
        if (!mounted) return;
        Navigator.of(context).pushReplacement(MaterialPageRoute(
            builder: (_) => DashboardScreen(
                  token: data['data']['token'],
                  userName: userName,
                )));
      } else {
        setState(() {
          _error = data['error']?.toString() ?? 'Login failed';
        });
      }
    } catch (e) {
      setState(() {
        _error = e.toString();
      });
    } finally {
      setState(() {
        _busy = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
              colors: [Color(0xFF4F46E5), Color(0xFF6366F1), Color(0xFF818CF8)],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight),
        ),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 370),
            child: Card(
              elevation: 10,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(26)),
              child: Padding(
                padding: const EdgeInsets.all(28),
                child: Form(
                  key: _formKey,
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    SizedBox(
                        height: 90,
                        width: 90,
                        child: Image.asset('assets/images/icon.png',
                            fit: BoxFit.contain)),
                    const SizedBox(height: 14),
                    Text('Batighor School Management',
                        textAlign: TextAlign.center,
                        style: Theme.of(context)
                            .textTheme
                            .titleMedium
                            ?.copyWith(fontWeight: FontWeight.bold)),
                    const SizedBox(height: 24),
                    TextFormField(
                      controller: _userCtrl,
                      decoration: const InputDecoration(
                          labelText: 'Username',
                          prefixIcon: Icon(Icons.person_outline)),
                      validator: (v) =>
                          v == null || v.isEmpty ? 'Required' : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _passCtrl,
                      decoration: InputDecoration(
                        labelText: 'Password',
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          tooltip:
                              _passObscure ? 'Show password' : 'Hide password',
                          icon: Icon(_passObscure
                              ? Icons.visibility_outlined
                              : Icons.visibility_off_outlined),
                          onPressed: () => setState(() {
                            _passObscure = !_passObscure;
                          }),
                        ),
                      ),
                      obscureText: _passObscure,
                      validator: (v) =>
                          v == null || v.isEmpty ? 'Required' : null,
                    ),
                    const SizedBox(height: 20),
                    if (_error != null)
                      Text(_error!, style: const TextStyle(color: Colors.red)),
                    const SizedBox(height: 10),
                    SizedBox(
                        width: double.infinity,
                        child: ElevatedButton(
                          onPressed: _busy ? null : _login,
                          style: ElevatedButton.styleFrom(
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(14))),
                          child: _busy
                              ? SizedBox(
                                  height: 36,
                                  width: 36,
                                  child:
                                      Image.asset('assets/images/loading.gif'))
                              : const Text('Login'),
                        )),
                  ]),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class DashboardScreen extends StatefulWidget {
  final String token;
  final String? userName;
  const DashboardScreen({super.key, required this.token, this.userName});
  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  List<dynamic> duties = [];
  bool loading = true;
  String? error;
  String? _userName;
  String? _userId;
  bool _isController = false;

  @override
  void initState() {
    super.initState();
    _userName = widget.userName;
    _load();
    _loadUserInfo();
  }

  Future<void> _load() async {
    setState(() => loading = true);
    try {
      duties = await ApiService.getDuties();
    } catch (e) {
      error = e.toString();
    }
    setState(() => loading = false);
  }

  Future<void> _loadUserInfo() async {
    final prefs = await SharedPreferences.getInstance();
    if (_userName == null || _userName!.isEmpty) {
      final name = prefs.getString('user_name');
      if (mounted) setState(() => _userName = name);
    }
    final userId = prefs.getString('user_id');
    if (userId != null && userId.isNotEmpty) {
      if (mounted) {
        setState(() => _userId = userId);
        _checkControllerStatus();
      }
    }
  }

  Future<void> _checkControllerStatus() async {
    if (_userId == null) return;
    try {
      final isController = await ApiService.isController(_userId!);
      if (mounted) {
        setState(() {
          _isController = isController;
        });
      }
    } catch (e) {
      // Silently fail, as it's not a critical feature
      print('Failed to check controller status: $e');
    }
  }

  Future<void> _logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('token');
    await prefs.remove('user_name');
    await prefs.remove('user_username');
    await prefs.remove('user_id');
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const LoginScreen()), (r) => false);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Dashboard'),
        actions: [
          if (_userName != null && _userName!.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(right: 8.0),
              child: Center(
                  child: Row(children: [
                const Icon(Icons.account_circle_outlined, size: 22),
                const SizedBox(width: 6),
                Text(_userName!,
                    style: const TextStyle(fontWeight: FontWeight.w500)),
                const SizedBox(width: 12),
              ])),
            ),
          IconButton(
              tooltip: 'Logout',
              onPressed: _logout,
              icon: const Icon(Icons.logout)),
        ],
      ),
      body: Padding(
        padding: const EdgeInsets.all(8.0),
        child: GridView.count(
          crossAxisCount: 2,
          crossAxisSpacing: 10,
          mainAxisSpacing: 10,
          children: <Widget>[
            _buildDashboardCard(
                context, 'Today\'s Duties', Icons.event_note, Colors.blue, () {
              Navigator.push(context,
                  MaterialPageRoute(builder: (context) => DutiesScreen()));
            }),
            _buildDashboardCard(
                context, 'Exam Seat Plan', Icons.event_seat, Colors.green, () {
              Navigator.push(context,
                  MaterialPageRoute(builder: (context) => SeatPlanScreen()));
            }),
            _buildDashboardCard(
                context, 'Marks Entry', Icons.edit, Colors.orange, () {
              Navigator.push(context,
                  MaterialPageRoute(builder: (context) => MarksEntryScreen()));
            }),
            if (_isController)
              _buildDashboardCard(context, 'Room Duty Allocation',
                  Icons.supervisor_account, Colors.purple, () {
                Navigator.push(
                    context,
                    MaterialPageRoute(
                        builder: (context) => RoomDutyAllocationScreen()));
              }),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: _load,
        child: const Icon(Icons.refresh),
      ),
    );
  }

  Card _buildDashboardCard(BuildContext context, String title, IconData icon,
      Color color, VoidCallback onTap) {
    return Card(
      elevation: 4,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircleAvatar(
                radius: 28,
                backgroundColor: color,
                child: Icon(icon, size: 32, color: Colors.white),
              ),
              const SizedBox(height: 12),
              Text(
                title,
                textAlign: TextAlign.center,
                style:
                    const TextStyle(fontSize: 16, fontWeight: FontWeight.w500),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class DutiesScreen extends StatefulWidget {
  const DutiesScreen({super.key});
  @override
  State<DutiesScreen> createState() => _DutiesScreenState();
}

class _DutiesScreenState extends State<DutiesScreen> {
  String _date = '';
  List<dynamic> _plans = [];
  List<dynamic> _rooms = [];
  List<dynamic> _students = [];
  String? _selectedPlanId;
  String? _selectedRoomId;
  bool _loadingPlans = true;
  bool _loadingRooms = false;
  bool _loadingStudents = false;
  bool _bulkSaving = false;
  String? _userName;

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    final prefs = await SharedPreferences.getInstance();
    _userName = prefs.getString('user_name');
    _date = _todayIso();
    await _loadPlans();
  }

  String _displayDate(String iso) {
    try {
      final dt = DateTime.parse(iso);
      return DateFormat('dd-MM-yyyy').format(dt);
    } catch (_) {
      return iso;
    }
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final initial = DateTime.tryParse(_date) ?? now;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(now.year - 1),
      lastDate: DateTime(now.year + 1),
    );
    if (picked != null) {
      setState(() {
        _date = picked.toIso8601String().substring(0, 10);
        _selectedPlanId = null;
        _selectedRoomId = null;
        _students.clear();
      });
      await _loadPlans();
    }
  }

  String _todayIso() => DateTime.now().toIso8601String().substring(0, 10);

  Future<void> _loadPlans() async {
    setState(() {
      _loadingPlans = true;
    });
    try {
      // Show plans irrespective of date; rooms/students remain date-scoped
      _plans = await ApiService.getSeatPlans('');
      if (_plans.isNotEmpty) {
        _selectedPlanId ??= _plans.first['id'].toString();
      }
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Failed to load plans: $e')));
    } finally {
      setState(() {
        _loadingPlans = false;
      });
      if (_selectedPlanId != null) _loadRooms();
    }
  }

  Future<void> _loadRooms() async {
    if (_selectedPlanId == null) return;
    setState(() {
      _loadingRooms = true;
      _rooms = [];
    });
    try {
      _rooms = await ApiService.getRooms(
          _selectedPlanId!, _date); // expects id, room_no, title
      if (_rooms.isNotEmpty) {
        _selectedRoomId ??= _rooms.first['id'].toString();
      }
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Failed to load rooms: $e')));
    } finally {
      setState(() {
        _loadingRooms = false;
      });
      if (_selectedRoomId != null) _loadStudents();
    }
  }

  Future<void> _loadStudents() async {
    if (_selectedPlanId == null || _selectedRoomId == null) return;
    setState(() {
      _loadingStudents = true;
      _students = [];
    });
    try {
      // Reuse existing attendance endpoint
      _students = await ApiService.getAttendance(
          _date, int.parse(_selectedPlanId!), int.parse(_selectedRoomId!));
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Failed to load students: $e')));
    } finally {
      setState(() {
        _loadingStudents = false;
      });
    }
  }

  Map<String, int> _computeStats() {
    int total = _students.length;
    int male = 0, female = 0, present = 0, absent = 0;
    for (final s in _students) {
      final g = (s['gender'] ?? '').toString().toLowerCase();
      if (g == 'male')
        male++;
      else if (g == 'female') female++;
      final st = (s['status'] ?? '').toString();
      if (st == 'present')
        present++;
      else if (st == 'absent') absent++;
    }
    return {
      'total': total,
      'male': male,
      'female': female,
      'present': present,
      'absent': absent
    };
  }

  Map<String, int> _classCounts() {
    final Map<String, int> counts = {};
    for (final s in _students) {
      final cname = (s['class_name'] ?? '').toString();
      if (cname.isEmpty) continue;
      counts[cname] = (counts[cname] ?? 0) + 1;
    }
    return counts;
  }

  Future<void> _markSingle(dynamic student, String status) async {
    final sid = student['student_id'];
    if (sid == null) return;
    setState(() {
      student['status'] = status;
    });
    try {
      await ApiService.submitAttendance(
          _date, int.parse(_selectedPlanId!), int.parse(_selectedRoomId!), [
        {'student_id': sid, 'status': status}
      ]);
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('সংরক্ষণ ব্যর্থ: $e')));
    } finally {
      setState(() {});
    }
  }

  Future<void> _bulkMark(String mode) async {
    if (_students.isEmpty) return;
    setState(() {
      _bulkSaving = true;
    });
    for (final s in _students) {
      s['status'] = mode;
    }
    try {
      final entries = _students
          .map((s) => {'student_id': s['student_id'], 'status': mode})
          .toList();
      await ApiService.submitAttendance(_date, int.parse(_selectedPlanId!),
          int.parse(_selectedRoomId!), entries);
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Bulk save failed: $e')));
    } finally {
      setState(() {
        _bulkSaving = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final stats = _computeStats();
    final classes = _classCounts();
    return Scaffold(
      appBar: AppBar(
        title: const Text("Today's Duties"),
        actions: [
          if (_userName != null)
            Padding(
                padding: const EdgeInsets.only(right: 12),
                child: Center(child: Text(_userName!)))
        ],
      ),
      body: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _buildFilters(),
            const SizedBox(height: 8),
            _buildStatsBar(stats, classes),
            const SizedBox(height: 8),
            Expanded(child: _buildStudentsTable()),
          ],
        ),
      ),
      floatingActionButton: (_students.isNotEmpty)
          ? FloatingActionButton.extended(
              onPressed: _bulkSaving ? null : () => _bulkMark('present'),
              label: _bulkSaving
                  ? const Text('Saving...')
                  : const Text('Mark All Present'),
              icon: const Icon(Icons.playlist_add_check),
            )
          : null,
    );
  }

  Widget _buildFilters() {
    return Card(
      elevation: 2,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Wrap(spacing: 12, runSpacing: 12, children: [
              SizedBox(
                width: 200,
                child: _loadingPlans
                    ? const _LoadingBox(label: 'Plans')
                    : DropdownButtonFormField<String>(
                        decoration:
                            const InputDecoration(labelText: 'Seat Plan'),
                        value: _selectedPlanId,
                        items: _plans
                            .map((p) => DropdownMenuItem(
                                  value: p['id'].toString(),
                                  child:
                                      Text('${p['plan_name']} (${p['shift']})'),
                                ))
                            .toList(),
                        onChanged: (v) {
                          setState(() {
                            _selectedPlanId = v;
                            _selectedRoomId = null;
                          });
                          _loadRooms();
                        },
                      ),
              ),
              SizedBox(
                width: 150,
                child: TextFormField(
                  decoration: InputDecoration(
                      labelText: 'Date',
                      suffixIcon: const Icon(Icons.calendar_month)),
                  initialValue: _displayDate(_date),
                  readOnly: true,
                  onTap: _pickDate,
                ),
              ),
              SizedBox(
                width: 160,
                child: _loadingRooms
                    ? const _LoadingBox(label: 'Rooms')
                    : DropdownButtonFormField<String>(
                        decoration: const InputDecoration(labelText: 'Room'),
                        value: _selectedRoomId,
                        items: _rooms
                            .map((r) => DropdownMenuItem(
                                  value: r['id'].toString(),
                                  child: Text(r['title'] != null &&
                                          r['title'].toString().isNotEmpty
                                      ? '${r['room_no']} - ${r['title']}'
                                      : '${r['room_no']}'),
                                ))
                            .toList(),
                        onChanged: (v) {
                          setState(() {
                            _selectedRoomId = v;
                          });
                          _loadStudents();
                        },
                      ),
              ),
              if (_students.isNotEmpty)
                ElevatedButton.icon(
                  onPressed: _bulkSaving ? null : () => _bulkMark('absent'),
                  icon: const Icon(Icons.block),
                  label: const Text('Mark All Absent'),
                  style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.redAccent),
                ),
            ])
          ],
        ),
      ),
    );
  }

  Widget _buildStatsBar(Map<String, int> stats, Map<String, int> classCounts) {
    if (_loadingStudents)
      return const SizedBox(
          height: 48, child: Center(child: CircularProgressIndicator()));
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(children: [
        _statChip('Total', stats['total']!, Colors.indigo),
        _statChip('Male', stats['male']!, Colors.blue),
        _statChip('Female', stats['female']!, Colors.pink),
        _statChip('Present', stats['present']!, Colors.green),
        _statChip('Absent', stats['absent']!, Colors.red),
        ...classCounts.entries.map((e) => _classChip(e.key, e.value)),
      ]),
    );
  }

  Widget _statChip(String label, int value, Color color) {
    return Container(
      margin: const EdgeInsets.only(right: 8),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration:
          BoxDecoration(color: color, borderRadius: BorderRadius.circular(8)),
      child: Row(children: [
        Text(label, style: const TextStyle(color: Colors.white, fontSize: 12)),
        const SizedBox(width: 4),
        Text(value.toString(),
            style: const TextStyle(
                color: Colors.white, fontWeight: FontWeight.bold)),
      ]),
    );
  }

  Widget _classChip(String label, int value) {
    return Container(
      margin: const EdgeInsets.only(right: 6),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
      decoration: BoxDecoration(
          color: Colors.grey.shade200,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: Colors.grey.shade400)),
      child: Row(children: [
        Text(label, style: const TextStyle(fontSize: 12)),
        const SizedBox(width: 4),
        Text(value.toString(),
            style: const TextStyle(fontWeight: FontWeight.bold))
      ]),
    );
  }

  Widget _buildStudentsTable() {
    if (_loadingStudents) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_students.isEmpty) {
      return const Center(child: Text('No students loaded'));
    }
    return ListView.separated(
      itemCount: _students.length,
      separatorBuilder: (_, __) => const Divider(height: 1),
      itemBuilder: (context, index) {
        final s = _students[index];
        final status = (s['status'] ?? '').toString();
        final seatInfo = s['seat'] != null
            ? 'Seat C${s['seat']['col_no']} B${s['seat']['bench_no']}${s['seat']['position']}'
            : '';
        return ListTile(
          dense: true,
          title: Text('${s['roll_no'] ?? ''}  ${s['student_name'] ?? ''}',
              maxLines: 2, overflow: TextOverflow.ellipsis),
          subtitle: Text(
              '${s['class_name'] ?? ''}${seatInfo.isNotEmpty ? ' • $seatInfo' : ''}'),
          trailing: ToggleButtons(
            isSelected: [status == 'present', status == 'absent'],
            constraints: const BoxConstraints(minHeight: 36, minWidth: 64),
            onPressed: (i) {
              _markSingle(s, i == 0 ? 'present' : 'absent');
            },
            borderRadius: BorderRadius.circular(8),
            selectedColor: Colors.white,
            fillColor: status == 'present'
                ? Colors.green
                : (status == 'absent' ? Colors.red : Colors.indigo),
            children: const [Text('Present'), Text('Absent')],
          ),
        );
      },
    );
  }
}

class _LoadingBox extends StatelessWidget {
  final String label;
  const _LoadingBox({required this.label});
  @override
  Widget build(BuildContext context) {
    return InputDecorator(
      decoration:
          InputDecoration(labelText: label, border: const OutlineInputBorder()),
      child: const SizedBox(
          height: 24,
          child: Center(child: CircularProgressIndicator(strokeWidth: 2))),
    );
  }
}

class MarksEntryScreen extends StatefulWidget {
  @override
  _MarksEntryScreenState createState() => _MarksEntryScreenState();
}

class _MarksEntryScreenState extends State<MarksEntryScreen> {
  List<dynamic> _exams = [];
  String? _selectedExam; // exam id
  String? _selectedExamClassId; // class id derived from exam
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _fetchDropdownData();
  }

  Future<void> _fetchDropdownData() async {
    setState(() {
      _isLoading = true;
    });
    try {
      final exams = await ApiService.getExams();
      setState(() {
        _exams = exams;
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _isLoading = false;
      });
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Failed to load data: $e')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Marks Entry - Select Criteria'),
      ),
      body: _isLoading
          ? Center(
              child: Image.asset('assets/images/loading.gif',
                  width: 100, height: 100))
          : Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _buildDropdown(
                    hint: 'Select Exam',
                    value: _selectedExam,
                    items: _exams.map((exam) {
                      final label = (exam['label'] ??
                              "${exam['name']} - ${exam['class_name']}")
                          .toString();
                      return DropdownMenuItem(
                        value: exam['id'].toString(),
                        child: Text(label),
                      );
                    }).toList(),
                    onChanged: (value) {
                      setState(() {
                        _selectedExam = value;
                        final selected = _exams.firstWhere(
                            (e) => e['id'].toString() == value,
                            orElse: () => {});
                        _selectedExamClassId = selected['class_id']?.toString();
                      });
                    },
                  ),
                  SizedBox(height: 32),
                  ElevatedButton(
                    onPressed:
                        (_selectedExam != null && _selectedExamClassId != null)
                            ? () {
                                final selected = _exams.firstWhere(
                                    (e) => e['id'].toString() == _selectedExam,
                                    orElse: () => {});
                                final label = selected['label']?.toString();
                                Navigator.push(
                                  context,
                                  MaterialPageRoute(
                                    builder: (context) => SubjectsScreen(
                                      examId: _selectedExam!,
                                      classId: _selectedExamClassId!,
                                      sectionId: '',
                                      examLabel: label,
                                    ),
                                  ),
                                );
                              }
                            : null,
                    child: Text('Fetch Subjects'),
                    style: ElevatedButton.styleFrom(
                      padding: EdgeInsets.symmetric(vertical: 16),
                    ),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _buildDropdown({
    required String hint,
    required String? value,
    required List<DropdownMenuItem<String>> items,
    required ValueChanged<String?> onChanged,
  }) {
    return DropdownButtonFormField<String>(
      decoration: InputDecoration(
        labelText: hint,
        border: OutlineInputBorder(),
      ),
      initialValue: value,
      items: items,
      onChanged: onChanged,
    );
  }
}

class SubjectsScreen extends StatefulWidget {
  final String examId;
  final String classId;
  final String sectionId;
  final String? examLabel;

  SubjectsScreen(
      {required this.examId,
      required this.classId,
      required this.sectionId,
      this.examLabel});

  @override
  _SubjectsScreenState createState() => _SubjectsScreenState();
}

class _SubjectsScreenState extends State<SubjectsScreen> {
  late Future<List<dynamic>> _subjectsFuture;

  @override
  void initState() {
    super.initState();
    _subjectsFuture =
        ApiService.getSubjectsForTeacher(widget.examId, widget.classId);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Select Subject'),
      ),
      body: FutureBuilder<List<dynamic>>(
        future: _subjectsFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(
                child: Image.asset('assets/images/loading.gif',
                    width: 100, height: 100));
          } else if (snapshot.hasError) {
            return Center(child: Text('Error: ${snapshot.error}'));
          } else if (!snapshot.hasData || snapshot.data!.isEmpty) {
            return Center(
                child: Text('No subjects found for the selected criteria.'));
          } else {
            final subjects = snapshot.data!;
            return ListView.builder(
              itemCount: subjects.length,
              itemBuilder: (context, index) {
                final subject = subjects[index];
                return Card(
                  margin:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                  child: ListTile(
                    title: Text(subject['subject_name']),
                    subtitle: Text('Code: ${subject['subject_code']}'),
                    trailing: Icon(Icons.arrow_forward_ios),
                    onTap: () {
                      Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (context) => StudentListMarksScreen(
                            examId: widget.examId,
                            classId: widget.classId,
                            sectionId: widget.sectionId,
                            subjectId: subject['subject_id'].toString(),
                            subjectName:
                                subject['subject_name']?.toString() ?? '',
                            examLabel: widget.examLabel,
                          ),
                        ),
                      );
                    },
                  ),
                );
              },
            );
          }
        },
      ),
    );
  }
}

class StudentListMarksScreen extends StatefulWidget {
  final String examId;
  final String classId;
  final String sectionId;
  final String subjectId;
  final String? examLabel;
  final String? subjectName;

  StudentListMarksScreen(
      {required this.examId,
      required this.classId,
      required this.sectionId,
      required this.subjectId,
      this.examLabel,
      this.subjectName});

  @override
  _StudentListMarksScreenState createState() => _StudentListMarksScreenState();
}

class _StudentListMarksScreenState extends State<StudentListMarksScreen> {
  late Future<Map<String, dynamic>> _dataFuture;
  final Map<String, TextEditingController> _cqControllers = {};
  final Map<String, TextEditingController> _mcqControllers = {};
  final Map<String, TextEditingController> _prControllers = {};
  Map<String, dynamic> _meta = {};
  final Map<String, bool> _rowSavedOk = {};
  // Debouncers removed; saving now happens on focus loss only
  final Map<String, int> _totals = {}; // cache of totals per student
  bool _showTotals =
      false; // user toggle for total column visibility (default OFF for speed)

  @override
  void initState() {
    super.initState();
    _dataFuture = ApiService.getStudentsForMarking(
        widget.examId, widget.classId, widget.subjectId);
  }

  @override
  void dispose() {
    _cqControllers.values.forEach((c) => c.dispose());
    _mcqControllers.values.forEach((c) => c.dispose());
    _prControllers.values.forEach((c) => c.dispose());
    super.dispose();
  }

  void _recomputeTotal(String studentId) {
    if (!_showTotals) return; // skip computation when totals are hidden
    final cq =
        double.tryParse(_cqControllers[studentId]?.text.trim() ?? '') ?? 0;
    final mcq =
        double.tryParse(_mcqControllers[studentId]?.text.trim() ?? '') ?? 0;
    final pr =
        double.tryParse(_prControllers[studentId]?.text.trim() ?? '') ?? 0;
    _totals[studentId] = (cq + mcq + pr).toInt();
  }

  void _saveSingleStudentFireAndForget(String studentId) {
    final cq =
        double.tryParse(_cqControllers[studentId]?.text.trim() ?? '') ?? 0;
    final mcq =
        double.tryParse(_mcqControllers[studentId]?.text.trim() ?? '') ?? 0;
    final pr =
        double.tryParse(_prControllers[studentId]?.text.trim() ?? '') ?? 0;

    // Fire-and-forget save to keep UI snappy
    ApiService.submitMarks(widget.examId, widget.classId, widget.subjectId, [
      {
        'student_id': int.tryParse(studentId) ?? 0,
        'creative': cq,
        'objective': mcq,
        'practical': pr,
      }
    ]).then((_) {
      if (!mounted) return;
      setState(() {
        _rowSavedOk[studentId] = true;
        _recomputeTotal(
            studentId); // update cached total after save (if visible)
      });
    }).catchError((e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('মার্কস সংরক্ষণে সমস্যা: $e')),
      );
    });
  }

  void _validateAndClamp(TextEditingController c, int max, String partLabel) {
    String t = c.text.trim();
    double v = double.tryParse(t) ?? 0;
    if (v < 0) {
      c.text = '0';
      c.selection =
          TextSelection.fromPosition(TextPosition(offset: c.text.length));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$partLabel এর মান 0 থেকে $max এর মধ্যে দিন')),
        );
      }
    } else if (v > max) {
      c.text = max.toString();
      c.selection =
          TextSelection.fromPosition(TextPosition(offset: c.text.length));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$partLabel এর মান 0 থেকে $max এর মধ্যে দিন')),
        );
      }
    }
  }

  // _partField removed; inline editors are used inside DataTable cells

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Enter Marks'),
      ),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _dataFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(
                child: Image.asset('assets/images/loading.gif',
                    width: 100, height: 100));
          } else if (snapshot.hasError) {
            return Center(child: Text('Error: ${snapshot.error}'));
          } else if (!snapshot.hasData ||
              (snapshot.data!['students'] as List).isEmpty) {
            return Center(child: Text('No students found.'));
          } else {
            final data = snapshot.data!;
            _meta = Map<String, dynamic>.from(data['meta'] ?? {});
            final students =
                (data['students'] as List).cast<Map<String, dynamic>>();
            final cqMax = (_meta['creativeMax'] ?? 0) as int;
            final mcqMax = (_meta['objectiveMax'] ?? 0) as int;
            final prMax = (_meta['practicalMax'] ?? 0) as int;

            List<DataColumn> cols = [
              DataColumn(label: Center(child: Text('Roll'))),
              DataColumn(label: Center(child: Text('Name'))),
            ];
            if (cqMax > 0)
              cols.add(DataColumn(label: Center(child: Text('CQ/$cqMax'))));
            if (mcqMax > 0)
              cols.add(DataColumn(label: Center(child: Text('MCQ/$mcqMax'))));
            if (prMax > 0)
              cols.add(DataColumn(label: Center(child: Text('PR/$prMax'))));
            if (_showTotals) {
              cols.add(DataColumn(label: Center(child: Text('Total'))));
            }

            final rows = students.map((student) {
              final studentId = student['student_id'].toString();
              _cqControllers.putIfAbsent(
                  studentId,
                  () => TextEditingController(
                      text: student['creative']?.toString() ?? ''));
              _mcqControllers.putIfAbsent(
                  studentId,
                  () => TextEditingController(
                      text: student['objective']?.toString() ?? ''));
              _prControllers.putIfAbsent(
                  studentId,
                  () => TextEditingController(
                      text: student['practical']?.toString() ?? ''));

              if (!_totals.containsKey(studentId)) {
                _recomputeTotal(studentId); // initial computation
              }
              final total = _totals[studentId] ?? 0;

              List<DataCell> cells = [
                DataCell(Container(
                    padding: EdgeInsets.zero,
                    child: Text('${student['roll_no'] ?? ''}'))),
                DataCell(Container(
                    padding: EdgeInsets.zero,
                    child: Text(student['name'] ?? ''))),
              ];
              if (cqMax > 0) {
                cells.add(DataCell(SizedBox(
                  width: 60,
                  child: Focus(
                    onFocusChange: (hasFocus) {
                      if (!hasFocus) {
                        _validateAndClamp(
                            _cqControllers[studentId]!, cqMax, 'CQ');
                        _saveSingleStudentFireAndForget(studentId);
                      }
                    },
                    child: TextField(
                      controller: _cqControllers[studentId],
                      keyboardType: TextInputType.number,
                      textAlign: TextAlign.center,
                      decoration: const InputDecoration(
                          isDense: true, border: OutlineInputBorder()),
                      // Removed per-keystroke total recompute for performance
                    ),
                  ),
                )));
              }
              if (mcqMax > 0) {
                cells.add(DataCell(SizedBox(
                  width: 60,
                  child: Focus(
                    onFocusChange: (hasFocus) {
                      if (!hasFocus) {
                        _validateAndClamp(
                            _mcqControllers[studentId]!, mcqMax, 'MCQ');
                        _saveSingleStudentFireAndForget(studentId);
                      }
                    },
                    child: TextField(
                      controller: _mcqControllers[studentId],
                      keyboardType: TextInputType.number,
                      textAlign: TextAlign.center,
                      decoration: const InputDecoration(
                          isDense: true, border: OutlineInputBorder()),
                      // Removed per-keystroke total recompute
                    ),
                  ),
                )));
              }
              if (prMax > 0) {
                cells.add(DataCell(SizedBox(
                  width: 60,
                  child: Focus(
                    onFocusChange: (hasFocus) {
                      if (!hasFocus) {
                        _validateAndClamp(
                            _prControllers[studentId]!, prMax, 'PR');
                        _saveSingleStudentFireAndForget(studentId);
                      }
                    },
                    child: TextField(
                      controller: _prControllers[studentId],
                      keyboardType: TextInputType.number,
                      textAlign: TextAlign.center,
                      decoration: const InputDecoration(
                          isDense: true, border: OutlineInputBorder()),
                      // Removed per-keystroke total recompute
                    ),
                  ),
                )));
              }
              if (_showTotals) {
                cells.add(DataCell(Container(
                    padding: EdgeInsets.zero,
                    child: Center(child: Text(total.toString())))));
              }

              return DataRow(cells: cells);
            }).toList();

            final headerText =
                (widget.examLabel != null && widget.examLabel!.isNotEmpty)
                    ? widget.examLabel!
                    : 'Exam: ${widget.examId}  Class: ${widget.classId}';

            return Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12.0),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(headerText,
                              style:
                                  const TextStyle(fontWeight: FontWeight.w600)),
                          if ((widget.subjectName ?? '').isNotEmpty)
                            Text('Subject: ${widget.subjectName}',
                                style: const TextStyle(color: Colors.black54)),
                          Row(
                            children: [
                              const Text('Show Total'),
                              Switch(
                                value: _showTotals,
                                onChanged: (v) => setState(() {
                                  _showTotals = v;
                                }),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                Expanded(
                  child: SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(minWidth: 20),
                      child: SingleChildScrollView(
                        child: Padding(
                          padding: const EdgeInsets.only(left: 12.0),
                          child: DataTable(
                            columns: cols,
                            rows: rows,
                            headingRowColor: WidgetStateProperty.resolveWith(
                                (_) => Colors.grey.shade100),
                            columnSpacing: 16,
                            horizontalMargin: 8,
                            headingRowHeight: 40,
                            dataRowMinHeight: 36,
                            dataRowMaxHeight: 44,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            );
          }
        },
      ),
    );
  }
}

class RoomDutyAllocationScreen extends StatefulWidget {
  @override
  _RoomDutyAllocationScreenState createState() =>
      _RoomDutyAllocationScreenState();
}

class _RoomDutyAllocationScreenState extends State<RoomDutyAllocationScreen> {
  List<dynamic> _plans = [];
  String? _selectedPlanId;
  List<String> _examDates = [];
  String? _selectedDate;
  List<dynamic> _rooms = [];
  List<dynamic> _teachers = [];
  Map<String, String> _dutyMap = {}; // room_id -> teacher_user_id

  bool _loadingPlans = true;
  bool _loadingDates = false;
  bool _loadingRoomsAndDuties = false;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _loadInitialData();
  }

  Future<void> _loadInitialData() async {
    setState(() => _loadingPlans = true);
    try {
      final plans = await ApiService.getSeatPlans('');
      final teachers = await ApiService.getTeachers();
      if (mounted) {
        setState(() {
          _plans = plans;
          _teachers = teachers;
          if (_plans.isNotEmpty) {
            _selectedPlanId = _plans.first['id']?.toString();
            _loadDatesForPlan();
          }
        });
      }
    } catch (e) {
      _showError('Failed to load initial data: $e');
    } finally {
      if (mounted) setState(() => _loadingPlans = false);
    }
  }

  Future<void> _loadDatesForPlan() async {
    if (_selectedPlanId == null) return;
    setState(() {
      _loadingDates = true;
      _examDates = [];
      _selectedDate = null;
      _rooms = [];
      _dutyMap = {};
    });
    try {
      final dates = await ApiService.getPlanDates(_selectedPlanId!);
      if (mounted) {
        setState(() {
          _examDates = dates;
          if (_examDates.isNotEmpty) {
            _selectedDate = _examDates.first;
            _loadRoomsAndDuties();
          }
        });
      }
    } catch (e) {
      _showError('Failed to load dates: $e');
    } finally {
      if (mounted) setState(() => _loadingDates = false);
    }
  }

  Future<void> _loadRoomsAndDuties() async {
    if (_selectedPlanId == null || _selectedDate == null) return;
    setState(() => _loadingRoomsAndDuties = true);
    try {
      final rooms = await ApiService.getRooms(_selectedPlanId!, _selectedDate!);
      final duties =
          await ApiService.getDutiesForPlan(_selectedPlanId!, _selectedDate!);
      if (mounted) {
        setState(() {
          _rooms = rooms;
          _dutyMap = duties;
        });
      }
    } catch (e) {
      _showError('Failed to load rooms/duties: $e');
    } finally {
      if (mounted) setState(() => _loadingRoomsAndDuties = false);
    }
  }

  Future<void> _saveDuties() async {
    if (_selectedPlanId == null || _selectedDate == null) return;
    setState(() => _saving = true);
    try {
      // Filter out unassigned rooms
      final Map<String, String> dutiesToSave = {};
      _dutyMap.forEach((roomId, teacherId) {
        if (teacherId.isNotEmpty) {
          dutiesToSave[roomId] = teacherId;
        }
      });

      await ApiService.saveDuties(
          _selectedPlanId!, _selectedDate!, dutiesToSave);
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Duties saved successfully')));
      }
    } catch (e) {
      _showError('Failed to save duties: $e');
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  void _showError(String message) {
    if (mounted) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Room Duty Allocation')),
      body: Column(
        children: [
          _buildFilters(),
          Expanded(
            child: _loadingPlans
                ? Center(child: CircularProgressIndicator())
                : _buildDutyTable(),
          ),
        ],
      ),
      floatingActionButton: (_rooms.isNotEmpty && !_saving)
          ? FloatingActionButton.extended(
              onPressed: _saveDuties,
              icon: Icon(Icons.save),
              label: Text('Save Duties'),
            )
          : null,
    );
  }

  Widget _buildFilters() {
    return Card(
      margin: EdgeInsets.all(8),
      child: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Wrap(
          spacing: 16,
          runSpacing: 12,
          children: [
            SizedBox(
              width: 250,
              child: DropdownButtonFormField<String>(
                decoration: InputDecoration(labelText: 'Seat Plan'),
                value: _selectedPlanId,
                items: _plans
                    .map((p) => DropdownMenuItem(
                          value: p['id'].toString(),
                          child: Text('${p['plan_name']} (${p['shift']})'),
                        ))
                    .toList(),
                onChanged: (v) {
                  if (v != null) {
                    setState(() => _selectedPlanId = v);
                    _loadDatesForPlan();
                  }
                },
              ),
            ),
            SizedBox(
              width: 200,
              child: _loadingDates
                  ? _LoadingBox(label: 'Date')
                  : DropdownButtonFormField<String>(
                      decoration: InputDecoration(labelText: 'Date'),
                      value: _selectedDate,
                      items: _examDates
                          .map((d) => DropdownMenuItem(
                                value: d,
                                child: Text(() {
                                  try {
                                    return DateFormat('dd-MM-yyyy')
                                        .format(DateTime.parse(d));
                                  } catch (_) {
                                    return d;
                                  }
                                }()),
                              ))
                          .toList(),
                      onChanged: (v) {
                        if (v != null) {
                          setState(() => _selectedDate = v);
                          _loadRoomsAndDuties();
                        }
                      },
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildDutyTable() {
    if (_loadingRoomsAndDuties) {
      return Center(child: CircularProgressIndicator());
    }
    if (_rooms.isEmpty) {
      return Center(child: Text('No rooms found for the selected criteria.'));
    }
    return ListView.builder(
      padding: EdgeInsets.all(8),
      itemCount: _rooms.length,
      itemBuilder: (context, index) {
        final room = _rooms[index];
        final roomId = room['id'].toString();
        final assignedTeacherId = _dutyMap[roomId] ?? '';

        return Card(
          margin: EdgeInsets.symmetric(vertical: 4),
          child: ListTile(
            title: Text(
                'Room: ${room['room_no']} ${room['title'] != null ? '- ' + room['title'] : ''}'),
            subtitle: DropdownButton<String>(
              isExpanded: true,
              value: assignedTeacherId.isEmpty ? null : assignedTeacherId,
              hint: Text('-- Select Teacher --'),
              items: [
                DropdownMenuItem<String>(
                  value: '',
                  child: Text('-- Unassigned --'),
                ),
                ..._teachers.map((t) => DropdownMenuItem(
                      value: t['user_id'].toString(),
                      child: Text(t['display_name']),
                    )),
              ],
              onChanged: (value) {
                setState(() {
                  // Enforce one teacher per room
                  final currentAssignments = Map<String, String>.from(_dutyMap);
                  // Clear previous assignment of this teacher if any
                  currentAssignments.forEach((rId, tId) {
                    if (tId == value) {
                      currentAssignments[rId] = '';
                    }
                  });
                  _dutyMap = currentAssignments;
                  _dutyMap[roomId] = value ?? '';
                });
              },
            ),
          ),
        );
      },
    );
  }
}

class SeatPlanScreen extends StatefulWidget {
  const SeatPlanScreen({super.key});
  @override
  State<SeatPlanScreen> createState() => _SeatPlanScreenState();
}

class _SeatPlanScreenState extends State<SeatPlanScreen> {
  List<dynamic> _plans = [];
  String? _selectedPlanId;
  String _search = '';
  List<dynamic> _results = [];
  bool _loadingPlans = true;
  bool _searching = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadPlans();
  }

  Future<void> _loadPlans() async {
    setState(() {
      _loadingPlans = true;
      _error = null;
    });
    try {
      // Always show all active plans; seat allocations are plan-scoped
      _plans = await ApiService.getSeatPlans('');
      if (_plans.isNotEmpty) {
        _selectedPlanId ??= _plans.first['id']?.toString();
      }
    } catch (e) {
      _error = e.toString();
    } finally {
      if (mounted) {
        setState(() {
          _loadingPlans = false;
        });
      }
    }
  }

  Future<void> _doSearch() async {
    if (_selectedPlanId == null || _search.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('একটি প্ল্যান নির্বাচন করে সার্চ দিন')));
      return;
    }
    setState(() {
      _searching = true;
      _results = [];
      _error = null;
    });
    try {
      _results =
          await ApiService.searchSeatPlan(int.parse(_selectedPlanId!), _search);
      if (_results.isEmpty && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('"$_search" এর জন্য কোন সিট পাওয়া যায়নি')));
      }
    } catch (e) {
      _error = e.toString();
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('সার্চ ব্যর্থ: $e')));
      }
    } finally {
      if (mounted) {
        setState(() {
          _searching = false;
        });
      }
    }
  }

  String _sideLabel(String? code) {
    if (code == null) return '';
    return code == 'R' ? 'Right' : (code == 'L' ? 'Left' : code);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Seat Plan Finder')),
      body: SingleChildScrollView(
        child: Padding(
          padding: const EdgeInsets.all(12.0),
          child: Column(
            children: [
              Card(
                elevation: 2,
                child: Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Search Student Seat',
                          style: TextStyle(fontWeight: FontWeight.w600)),
                      const SizedBox(height: 8),
                      Wrap(spacing: 16, runSpacing: 12, children: [
                        SizedBox(
                          width: 220,
                          child: _loadingPlans
                              ? const _LoadingBox(label: 'Seat Plans')
                              : DropdownButtonFormField<String>(
                                  decoration: const InputDecoration(
                                      labelText: 'Seat Plan'),
                                  value: _selectedPlanId,
                                  items: _plans
                                      .map((p) => DropdownMenuItem<String>(
                                            value: p['id']?.toString(),
                                            child: Text(
                                                '${p['plan_name']} (${p['shift']})'),
                                          ))
                                      .toList(),
                                  onChanged: (v) {
                                    setState(() {
                                      _selectedPlanId = v;
                                    });
                                  },
                                ),
                        ),
                        SizedBox(
                          width: 240,
                          child: TextFormField(
                            decoration: const InputDecoration(
                                labelText: 'Roll বা Name লিখুন'),
                            onChanged: (v) => _search = v,
                            onFieldSubmitted: (_) => _doSearch(),
                          ),
                        ),
                        SizedBox(
                          height: 56,
                          child: ElevatedButton.icon(
                            onPressed: _searching ? null : _doSearch,
                            icon: const Icon(Icons.search),
                            label: _searching
                                ? const Text('Searching...')
                                : const Text('Search'),
                          ),
                        ),
                      ])
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 8),
              _buildResults(),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildResults() {
    if (_searching) {
      return const Center(heightFactor: 5, child: CircularProgressIndicator());
    }
    if (_error != null) {
      return Center(child: Text('ত্রুটি: $_error'));
    }
    if (_results.isEmpty) {
      return const Center(
          heightFactor: 5, child: Text('প্ল্যান সিলেক্ট করে সার্চ করুন'));
    }
    return Card(
      elevation: 1,
      child: Column(
        children: [
          Container(
            color: Colors.grey.shade100,
            padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
            child: Row(
              children: const [
                Expanded(
                    flex: 2,
                    child: Text('Roll',
                        style: TextStyle(fontWeight: FontWeight.w600))),
                Expanded(
                    flex: 3,
                    child: Text('Name',
                        style: TextStyle(fontWeight: FontWeight.w600))),
                Expanded(
                    flex: 2,
                    child: Text('Class',
                        style: TextStyle(fontWeight: FontWeight.w600))),
                Expanded(
                    flex: 2,
                    child: Text('Room',
                        style: TextStyle(fontWeight: FontWeight.w600))),
                Expanded(
                    flex: 1,
                    child: Text('Col',
                        style: TextStyle(fontWeight: FontWeight.w600))),
                Expanded(
                    flex: 1,
                    child: Text('Bench',
                        style: TextStyle(fontWeight: FontWeight.w600))),
                Expanded(
                    flex: 2,
                    child: Text('Side',
                        style: TextStyle(fontWeight: FontWeight.w600))),
              ],
            ),
          ),
          const Divider(height: 1),
          ListView.separated(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: _results.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final r = _results[i];
              return InkWell(
                onTap: () {},
                child: Padding(
                  padding:
                      const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
                  child: Row(
                    children: [
                      Expanded(
                          flex: 2, child: Text(r['roll_no']?.toString() ?? '')),
                      Expanded(
                          flex: 3,
                          child: Text(r['student_name']?.toString() ?? '')),
                      Expanded(
                          flex: 2,
                          child: Text(r['class_name']?.toString() ?? '')),
                      Expanded(
                          flex: 2, child: Text(r['room_no']?.toString() ?? '')),
                      Expanded(
                          flex: 1, child: Text((r['col_no'] ?? '').toString())),
                      Expanded(
                          flex: 1,
                          child: Text((r['bench_no'] ?? '').toString())),
                      Expanded(
                          flex: 2,
                          child: Text(_sideLabel(r['position']?.toString()))),
                    ],
                  ),
                ),
              );
            },
          )
        ],
      ),
    );
  }
}

class ApiService {
  static const String _baseUrl =
      'https://jss.batighorbd.com/api'; // Use 10.0.2.2 for Android emulator

  // Simple in-memory caches to speed up app usage within a session
  static final Map<String, List<dynamic>> _seatPlansCache = {}; // key: date
  static final Map<String, List<dynamic>> _roomsCache = {}; // key: planId|date
  static List<dynamic>? _teachersCache; // global list
  static final Map<String, List<String>> _planDatesCache = {}; // key: planId

  static Future<String> _getToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('token') ?? '';
  }

  static Future<dynamic> _get(String endpoint) async {
    final token = await _getToken();
    final response = await http.get(
      Uri.parse('$_baseUrl/$endpoint'),
      headers: {'Authorization': 'Bearer $token'},
    ).timeout(const Duration(seconds: 12));

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body);
      if (data['success']) {
        return data['data'];
      } else {
        throw Exception('API Error: ${data['error']}');
      }
    } else {
      throw Exception(
          'Failed to load data from $endpoint. Status code: ${response.statusCode}');
    }
  }

  static Future<dynamic> _post(
      String endpoint, Map<String, dynamic> body) async {
    final token = await _getToken();
    final response = await http
        .post(
          Uri.parse('$_baseUrl/$endpoint'),
          headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer $token',
          },
          body: jsonEncode(body),
        )
        .timeout(const Duration(seconds: 15));

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body);
      if (data['success']) {
        return data; // Return full response data for success cases
      } else {
        throw Exception('API Error: ${data['error']}');
      }
    } else {
      throw Exception(
          'Failed to post data to $endpoint. Status code: ${response.statusCode}');
    }
  }

  static Future<bool> isController(String userId) async {
    final data = await _get('is_controller.php?user_id=$userId');
    final v = data['is_controller'];
    if (v is bool) return v;
    if (v is num) return v != 0;
    final s = v?.toString().toLowerCase();
    return s == '1' || s == 'true' || s == 'yes';
  }

  static Future<List<dynamic>> getDuties() async {
    final data = await _get('teacher/duties.php?days=7');
    return data['duties'] as List<dynamic>;
  }

  // New: seat plans for a given date (expects array of {id, plan_name, shift})
  static Future<List<dynamic>> getSeatPlans(String date) async {
    // If already cached for this date, return directly
    if (_seatPlansCache.containsKey(date)) return _seatPlansCache[date]!;

    // Build endpoint: some backends may not support date filtering properly
    String endpoint = 'exam/seat_plans.php';
    if (date.isNotEmpty) {
      endpoint += '?date=$date';
    }

    List<dynamic> plans = [];
    try {
      final data = await _get(endpoint);
      plans = (data['plans'] ?? []) as List<dynamic>;
    } catch (e) {
      // Silent; will attempt fallback below
    }

    // Fallback: if date-specific query returned empty, try without date (in case server ignores/blocks date filter)
    if (plans.isEmpty && date.isNotEmpty) {
      try {
        final fbData = await _get('exam/seat_plans.php');
        plans = (fbData['plans'] ?? []) as List<dynamic>;
      } catch (e) {
        // If fallback also fails, propagate original empty list
      }
    }

    _seatPlansCache[date] = plans;
    return plans;
  }

  // New: rooms for a seat plan; if teacher, API should scope to assignments
  static Future<List<dynamic>> getRooms(String planId, String date) async {
    final key = '$planId|$date';
    if (_roomsCache.containsKey(key)) return _roomsCache[key]!;
    final data = await _get('exam/rooms.php?plan_id=$planId&date=$date');
    final rooms = (data['rooms'] ?? []) as List<dynamic>;
    _roomsCache[key] = rooms;
    return rooms;
  }

  // Seat plan search (finder) - expects endpoint returning {results:[...]}
  // Each result item should contain: roll_no, student_name, class_name, room_no, col_no, bench_no, position
  static Future<List<dynamic>> searchSeatPlan(int planId, String query) async {
    if (query.trim().isEmpty) return [];
    final data = await _get(
        'exam/seat_plan_search.php?plan_id=$planId&find=${Uri.encodeComponent(query.trim())}');
    return (data['results'] ?? []) as List<dynamic>;
  }

  // API methods for Room Duty Allocation
  static Future<List<dynamic>> getTeachers() async {
    if (_teachersCache != null) return _teachersCache!;
    final data = await _get('teachers.php');
    _teachersCache = data['teachers'] as List<dynamic>;
    return _teachersCache!;
  }

  static Future<List<String>> getPlanDates(String planId) async {
    if (_planDatesCache.containsKey(planId)) return _planDatesCache[planId]!;
    final data = await _get('exam/plan_dates.php?plan_id=$planId');
    final dates = (data['dates'] as List).map((d) => d.toString()).toList();
    _planDatesCache[planId] = dates;
    return dates;
  }

  static Future<Map<String, String>> getDutiesForPlan(
      String planId, String date) async {
    final data = await _get('exam/duties.php?plan_id=$planId&date=$date');
    return Map<String, String>.from((data['duties'] as Map)
        .map((k, v) => MapEntry(k.toString(), v.toString())));
  }

  static Future<void> saveDuties(
      String planId, String date, Map<String, String> duties) async {
    await _post('exam/duties.php?plan_id=$planId&date=$date', {
      'duties': duties,
    });
  }

  // Optional bulk helper (not used directly; we reuse submitAttendance)
  static Future<void> bulkMarkAttendance(String date, int planId, int roomId,
      String mode, List<dynamic> students) async {
    final entries = students
        .map((s) => {'student_id': s['student_id'], 'status': mode})
        .toList();
    await submitAttendance(date, planId, roomId, entries);
  }

  static Future<List<dynamic>> getAttendance(
      String date, int planId, int roomId) async {
    final data = await _get(
        'exam/attendance_get.php?date=$date&plan_id=$planId&room_id=$roomId');
    return data['students'] as List<dynamic>;
  }

  static Future<void> submitAttendance(String date, int planId, int roomId,
      List<Map<String, dynamic>> entries) async {
    await _post('exam/attendance_submit.php', {
      'date': date,
      'plan_id': planId,
      'room_id': roomId,
      'entries': entries,
    });
  }

  static Future<List<dynamic>> getExams() async {
    final data = await _get('marks/get_exams.php');
    return data['exams'];
  }

  static Future<List<dynamic>> getClasses() async {
    final data = await _get('marks/get_classes.php');
    return data['classes'];
  }

  static Future<List<dynamic>> getSections() async {
    final data = await _get('marks/get_sections.php');
    return data['sections'];
  }

  static Future<List<dynamic>> getSubjectsForTeacher(
      String examId, String classId) async {
    final data =
        await _get('marks/subjects.php?exam_id=$examId&class_id=$classId');
    return data['subjects'];
  }

  static Future<Map<String, dynamic>> getStudentsForMarking(
      String examId, String classId, String subjectId) async {
    final data = await _get(
        'marks/get_students_for_marking.php?exam_id=$examId&class_id=$classId&subject_id=$subjectId');
    return {'meta': data['meta'] ?? {}, 'students': data['students'] ?? []};
  }

  static Future<void> submitMarks(String examId, String classId,
      String subjectId, List<Map<String, dynamic>> marks) async {
    await _post('marks/submit.php', {
      'exam_id': examId,
      'class_id': classId,
      'subject_id': subjectId,
      'marks': marks,
    });
  }
}
