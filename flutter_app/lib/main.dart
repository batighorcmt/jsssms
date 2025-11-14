import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

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
        await prefs.setString('user_role', user['role']?.toString() ?? '');
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
                      decoration: const InputDecoration(
                          labelText: 'Password',
                          prefixIcon: Icon(Icons.lock_outline)),
                      obscureText: true,
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

  @override
  void initState() {
    super.initState();
    _userName = widget.userName;
    _load();
    _loadUserName();
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

  Future<void> _loadUserName() async {
    if (_userName != null && _userName!.isNotEmpty) return;
    final prefs = await SharedPreferences.getInstance();
    final name = prefs.getString('user_name');
    if (!mounted) return;
    setState(() => _userName = name);
  }

  Future<void> _logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('token');
    await prefs.remove('user_name');
    await prefs.remove('user_username');
    await prefs.remove('user_role');
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
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Today\'s Duties')),
      body: Center(
        child: Text('Duties Screen - Coming Soon!'),
      ),
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
      value: value,
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

  StudentListMarksScreen(
      {required this.examId,
      required this.classId,
      required this.sectionId,
      required this.subjectId,
      this.examLabel});

  @override
  _StudentListMarksScreenState createState() => _StudentListMarksScreenState();
}

class _StudentListMarksScreenState extends State<StudentListMarksScreen> {
  late Future<Map<String, dynamic>> _dataFuture;
  final Map<String, TextEditingController> _cqControllers = {};
  final Map<String, TextEditingController> _mcqControllers = {};
  final Map<String, TextEditingController> _prControllers = {};
  bool _isSaving = false;
  Map<String, dynamic> _meta = {};

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

  Future<void> _submitMarks() async {
    setState(() {
      _isSaving = true;
    });

    final marks = <Map<String, dynamic>>[];
    _cqControllers.forEach((sid, cqCtrl) {
      final mcq = _mcqControllers[sid]?.text ?? '';
      final pr = _prControllers[sid]?.text ?? '';
      marks.add({
        'student_id': int.tryParse(sid) ?? 0,
        'creative': double.tryParse(cqCtrl.text) ?? 0,
        'objective': double.tryParse(mcq) ?? 0,
        'practical': double.tryParse(pr) ?? 0,
      });
    });

    try {
      await ApiService.submitMarks(
          widget.examId, widget.classId, widget.subjectId, marks);
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Marks submitted successfully!')));
      Navigator.pop(context);
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Failed to submit marks: $e')));
    } finally {
      setState(() {
        _isSaving = false;
      });
    }
  }

  Widget _partField(
      {required TextEditingController controller,
      required String label,
      required int max}) {
    return TextField(
      controller: controller,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      textAlign: TextAlign.center,
      decoration: InputDecoration(
        labelText: label,
        border: const OutlineInputBorder(),
        contentPadding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
      ),
      onChanged: (v) {
        final d = double.tryParse(v) ?? 0;
        if (d < 0) controller.text = '0';
        if (d > max) controller.text = max.toString();
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Enter Marks'),
        actions: [
          IconButton(
            icon: Icon(Icons.save),
            onPressed: _isSaving ? null : _submitMarks,
          ),
        ],
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
            return Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Expanded(
                        child: Text(
                          widget.examLabel != null &&
                                  widget.examLabel!.isNotEmpty
                              ? widget.examLabel!
                              : 'Exam: ${widget.examId}  Class: ${widget.classId}',
                          style: const TextStyle(fontWeight: FontWeight.w600),
                        ),
                      ),
                    ],
                  ),
                ),
                Expanded(
                  child: ListView.builder(
                    itemCount: students.length,
                    itemBuilder: (context, index) {
                      final student = students[index];
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
                      final cqMax = (_meta['creativeMax'] ?? 0) as int;
                      final mcqMax = (_meta['objectiveMax'] ?? 0) as int;
                      final prMax = (_meta['practicalMax'] ?? 0) as int;
                      return Card(
                        margin: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 4),
                        child: Padding(
                          padding: const EdgeInsets.all(8.0),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(student['name'],
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w600)),
                              Text('Roll: ${student['roll_no']}'),
                              const SizedBox(height: 8),
                              Row(
                                children: [
                                  if (cqMax > 0)
                                    Expanded(
                                      child: _partField(
                                        controller: _cqControllers[studentId]!,
                                        label: 'CQ/$cqMax',
                                        max: cqMax,
                                      ),
                                    ),
                                  if (mcqMax > 0) const SizedBox(width: 8),
                                  if (mcqMax > 0)
                                    Expanded(
                                      child: _partField(
                                        controller: _mcqControllers[studentId]!,
                                        label: 'MCQ/$mcqMax',
                                        max: mcqMax,
                                      ),
                                    ),
                                  if (prMax > 0) const SizedBox(width: 8),
                                  if (prMax > 0)
                                    Expanded(
                                      child: _partField(
                                        controller: _prControllers[studentId]!,
                                        label: 'PR/$prMax',
                                        max: prMax,
                                      ),
                                    ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
                if (_isSaving)
                  Padding(
                    padding: const EdgeInsets.all(16.0),
                    child: Image.asset('assets/images/loading.gif',
                        width: 100, height: 100),
                  ),
              ],
            );
          }
        },
      ),
    );
  }
}

class SeatPlanScreen extends StatefulWidget {
  const SeatPlanScreen({super.key});
  @override
  State<SeatPlanScreen> createState() => _SeatPlanScreenState();
}

class _SeatPlanScreenState extends State<SeatPlanScreen> {
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Exam Seat Plan')),
      body: Center(
        child: Text('Seat Plan Screen - Coming Soon!'),
      ),
    );
  }
}

class AttendanceScreen extends StatefulWidget {
  final String token;
  final int planId;
  final int roomId;
  final String date;
  const AttendanceScreen(
      {super.key,
      required this.token,
      required this.planId,
      required this.roomId,
      required this.date});
  @override
  State<AttendanceScreen> createState() => _AttendanceScreenState();
}

class _AttendanceScreenState extends State<AttendanceScreen> {
  bool loading = true;
  String? error;
  List<dynamic> students = [];
  bool saving = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => loading = true);
    try {
      students = await ApiService.getAttendance(
          widget.date, widget.planId, widget.roomId);
    } catch (e) {
      error = e.toString();
    }
    setState(() => loading = false);
  }

  Future<void> _submit() async {
    setState(() => saving = true);
    try {
      final entries = students
          .map((s) => {'student_id': s['student_id'], 'status': s['status']})
          .toList();
      await ApiService.submitAttendance(
          widget.date, widget.planId, widget.roomId, entries);
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('Attendance saved')));
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.toString())));
    } finally {
      setState(() => saving = false);
    }
  }

  void _toggleStatus(int idx) {
    setState(() {
      final cur = students[idx]['status'];
      students[idx]['status'] = cur == 'present' ? 'absent' : 'present';
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Attendance ${widget.date}')),
      body: loading
          ? Center(
              child: SizedBox(
                  height: 100,
                  width: 100,
                  child: Image.asset('assets/images/loading.gif')))
          : error != null
              ? Center(child: Text(error!))
              : Column(children: [
                  Expanded(
                      child: ListView.builder(
                    itemCount: students.length,
                    itemBuilder: (c, i) {
                      final s = students[i];
                      return Card(
                        margin: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 4),
                        child: ListTile(
                          title: Text(s['student_name'] ??
                              'Student ${s['student_id']}'),
                          subtitle: Text(
                              'Roll ${s['roll_no'] ?? ''} • Seat C${s['seat']['col_no']} B${s['seat']['bench_no']}${s['seat']['position']}'),
                          trailing: Switch(
                            value: s['status'] == 'present',
                            onChanged: (_) => _toggleStatus(i),
                          ),
                          onTap: () => _toggleStatus(i),
                        ),
                      );
                    },
                  )),
                  Padding(
                    padding: const EdgeInsets.all(12),
                    child: SizedBox(
                      width: double.infinity,
                      child: ElevatedButton.icon(
                        icon: const Icon(Icons.save),
                        label: saving
                            ? SizedBox(
                                height: 36,
                                width: 36,
                                child: Image.asset('assets/images/loading.gif'))
                            : const Text('Save Attendance'),
                        onPressed: saving ? null : _submit,
                      ),
                    ),
                  )
                ]),
    );
  }
}

class ApiService {
  static const String _baseUrl =
      'https://jss.batighorbd.com/api'; // Use 10.0.2.2 for Android emulator

  static Future<String> _getToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('token') ?? '';
  }

  static Future<dynamic> _get(String endpoint) async {
    final token = await _getToken();
    final response = await http.get(
      Uri.parse('$_baseUrl/$endpoint'),
      headers: {'Authorization': 'Bearer $token'},
    );

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
    final response = await http.post(
      Uri.parse('$_baseUrl/$endpoint'),
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $token',
      },
      body: jsonEncode(body),
    );

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

  static Future<List<dynamic>> getDuties() async {
    final data = await _get('teacher/duties.php?days=7');
    return data['duties'] as List<dynamic>;
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
