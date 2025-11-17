import 'package:flutter/material.dart';
import '../../main.dart';

class StudentMgmtHomePage extends StatefulWidget {
  const StudentMgmtHomePage({super.key});
  @override
  State<StudentMgmtHomePage> createState() => _StudentMgmtHomePageState();
}

class _StudentMgmtHomePageState extends State<StudentMgmtHomePage> {
  Map<String, dynamic>? _counts;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final c = await ApiService.studentCounts();
      if (mounted)
        setState(() {
          _counts = c;
        });
    } catch (e) {
      if (mounted)
        setState(() {
          _error = e.toString();
        });
    } finally {
      if (mounted)
        setState(() {
          _loading = false;
        });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Student Management')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text('Error: $_error'))
              : Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Card(
                        child: ListTile(
                          title: const Text('Total Students'),
                          trailing: Text('${_counts?['total'] ?? 0}',
                              style: const TextStyle(
                                  fontSize: 18, fontWeight: FontWeight.w600)),
                        ),
                      ),
                      const SizedBox(height: 8),
                      const Text('Counts by Class',
                          style: TextStyle(fontWeight: FontWeight.w600)),
                      const SizedBox(height: 6),
                      Expanded(
                        child: ListView(
                          children: List<Widget>.from(
                            ((_counts?['by_class'] ?? []) as List<dynamic>).map(
                              (c) => ListTile(
                                leading: const Icon(Icons.school,
                                    color: Colors.indigo),
                                title: Text(c['class_name']?.toString() ?? ''),
                                trailing: Text(c['count'].toString()),
                              ),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
      floatingActionButton: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          FloatingActionButton.extended(
            heroTag: 'list',
            onPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const StudentListPage()),
              );
            },
            icon: const Icon(Icons.people),
            label: const Text('View Students'),
          ),
          const SizedBox(height: 8),
          FloatingActionButton.extended(
            heroTag: 'add',
            backgroundColor: Colors.green,
            onPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const StudentCreatePage()),
              );
            },
            icon: const Icon(Icons.person_add),
            label: const Text('Add Student'),
          ),
        ],
      ),
    );
  }
}

class StudentListPage extends StatefulWidget {
  const StudentListPage({super.key});
  @override
  State<StudentListPage> createState() => _StudentListPageState();
}

class _StudentListPageState extends State<StudentListPage> {
  int _page = 1;
  final int _perPage = 20;
  List<dynamic> _items = [];
  int _total = 0;
  bool _loading = true;
  String? _error;
  final _qCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ApiService.listStudents(
          page: _page, perPage: _perPage, q: _qCtrl.text.trim());
      if (mounted)
        setState(() {
          _total = data['total'] ?? 0;
          _items = (data['items'] ?? []) as List<dynamic>;
        });
    } catch (e) {
      if (mounted)
        setState(() {
          _error = e.toString();
        });
    } finally {
      if (mounted)
        setState(() {
          _loading = false;
        });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Students')),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.all(8.0),
          child: Row(children: [
            Expanded(
                child: TextField(
              controller: _qCtrl,
              decoration:
                  const InputDecoration(labelText: 'Search by ID/Name/Father'),
            )),
            const SizedBox(width: 8),
            ElevatedButton(onPressed: _load, child: const Text('Search')),
          ]),
        ),
        _loading ? const LinearProgressIndicator() : const SizedBox.shrink(),
        _error != null
            ? Padding(
                padding: const EdgeInsets.all(12),
                child: Text('Error: $_error'))
            : const SizedBox.shrink(),
        Expanded(
          child: ListView.separated(
            itemCount: _items.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final s = _items[i] as Map<String, dynamic>;
              final photoUrl = s['photo_url']?.toString();
              return ListTile(
                leading: CircleAvatar(
                  backgroundColor: Colors.indigo.shade100,
                  backgroundImage: (photoUrl != null && photoUrl.isNotEmpty)
                      ? NetworkImage(photoUrl)
                      : null,
                  child: (photoUrl == null || photoUrl.isEmpty)
                      ? const Icon(Icons.person)
                      : null,
                ),
                title: Text('${s['student_name'] ?? ''}'),
                subtitle: Text(
                    'ID: ${s['student_id'] ?? ''} • Class: ${s['class_name'] ?? ''}'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(
                        builder: (_) => StudentDetailPage(id: s['id'] as int)),
                  );
                },
              );
            },
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: [
              Text('Total: $_total'),
              const Spacer(),
              IconButton(
                  onPressed: _page > 1
                      ? () {
                          setState(() => _page--);
                          _load();
                        }
                      : null,
                  icon: const Icon(Icons.chevron_left)),
              Text('Page $_page'),
              IconButton(
                  onPressed: (_page * _perPage < _total)
                      ? () {
                          setState(() => _page++);
                          _load();
                        }
                      : null,
                  icon: const Icon(Icons.chevron_right)),
            ],
          ),
        )
      ]),
    );
  }
}

class StudentDetailPage extends StatefulWidget {
  final int id;
  const StudentDetailPage({super.key, required this.id});
  @override
  State<StudentDetailPage> createState() => _StudentDetailPageState();
}

class _StudentDetailPageState extends State<StudentDetailPage> {
  Map<String, dynamic>? _student;
  bool _loading = true;
  String? _error;
  bool _saving = false;
  final _nameCtrl = TextEditingController();
  final _sidCtrl = TextEditingController();
  final _rollCtrl = TextEditingController();
  final _classCtrl = TextEditingController();
  final _sectionCtrl = TextEditingController();
  final _mobileCtrl = TextEditingController();
  final _groupCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final s = await ApiService.getStudent(id: widget.id);
      _student = s;
      _nameCtrl.text = s['student_name']?.toString() ?? '';
      _sidCtrl.text = s['student_id']?.toString() ?? '';
      _rollCtrl.text = s['roll_no']?.toString() ?? '';
      _classCtrl.text = s['class_id']?.toString() ?? '';
      _sectionCtrl.text = s['section_id']?.toString() ?? '';
      _mobileCtrl.text = s['mobile_no']?.toString() ?? '';
      _groupCtrl.text = s['student_group']?.toString() ?? '';
      if (mounted) setState(() {});
    } catch (e) {
      if (mounted)
        setState(() {
          _error = e.toString();
        });
    } finally {
      if (mounted)
        setState(() {
          _loading = false;
        });
    }
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    try {
      final ok = await ApiService.updateStudent(widget.id, {
        'student_name': _nameCtrl.text.trim(),
        'student_id': _sidCtrl.text.trim(),
        'roll_no': _rollCtrl.text.trim(),
        'class_id': _classCtrl.text.trim(),
        'section_id': _sectionCtrl.text.trim(),
        'mobile_no': _mobileCtrl.text.trim(),
        'student_group': _groupCtrl.text.trim(),
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(ok ? 'Saved' : 'No changes')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Save failed: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Student Detail')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text('Error: $_error'))
              : Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: ListView(children: [
                    TextFormField(
                        controller: _nameCtrl,
                        decoration: const InputDecoration(labelText: 'Name')),
                    const SizedBox(height: 8),
                    TextFormField(
                        controller: _sidCtrl,
                        decoration:
                            const InputDecoration(labelText: 'Student ID')),
                    const SizedBox(height: 8),
                    TextFormField(
                        controller: _rollCtrl,
                        decoration: const InputDecoration(labelText: 'Roll')),
                    const SizedBox(height: 8),
                    TextFormField(
                        controller: _classCtrl,
                        decoration:
                            const InputDecoration(labelText: 'Class ID')),
                    const SizedBox(height: 8),
                    TextFormField(
                        controller: _sectionCtrl,
                        decoration:
                            const InputDecoration(labelText: 'Section ID')),
                    const SizedBox(height: 8),
                    TextFormField(
                        controller: _mobileCtrl,
                        decoration: const InputDecoration(labelText: 'Mobile')),
                    const SizedBox(height: 8),
                    TextFormField(
                        controller: _groupCtrl,
                        decoration: const InputDecoration(labelText: 'Group')),
                    const SizedBox(height: 16),
                    ElevatedButton(
                        onPressed: _saving ? null : _save,
                        child: Text(_saving ? 'Saving...' : 'Save')),
                  ]),
                ),
    );
  }
}

class StudentCreatePage extends StatefulWidget {
  const StudentCreatePage({super.key});
  @override
  State<StudentCreatePage> createState() => _StudentCreatePageState();
}

class _StudentCreatePageState extends State<StudentCreatePage> {
  final _formKey = GlobalKey<FormState>();
  final _nameCtrl = TextEditingController();
  final _sidCtrl = TextEditingController();
  final _classCtrl = TextEditingController();
  final _sectionCtrl = TextEditingController();
  final _rollCtrl = TextEditingController();
  bool _saving = false;

  Future<void> _create() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    try {
      final id = await ApiService.createStudent({
        'student_name': _nameCtrl.text.trim(),
        'student_id': _sidCtrl.text.trim(),
        'class_id': _classCtrl.text.trim(),
        'section_id': _sectionCtrl.text.trim(),
        'roll_no': _rollCtrl.text.trim(),
      });
      if (!mounted) return;
      if (id > 0) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Student created')),
        );
        Navigator.of(context).pop();
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Creation failed')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Creation failed: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Add Student')),
      body: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Form(
          key: _formKey,
          child: ListView(children: [
            TextFormField(
                controller: _nameCtrl,
                decoration: const InputDecoration(labelText: 'Name'),
                validator: (v) => (v == null || v.isEmpty) ? 'Required' : null),
            const SizedBox(height: 8),
            TextFormField(
                controller: _sidCtrl,
                decoration: const InputDecoration(labelText: 'Student ID'),
                validator: (v) => (v == null || v.isEmpty) ? 'Required' : null),
            const SizedBox(height: 8),
            TextFormField(
                controller: _classCtrl,
                decoration: const InputDecoration(labelText: 'Class ID'),
                validator: (v) => (v == null || v.isEmpty) ? 'Required' : null),
            const SizedBox(height: 8),
            TextFormField(
                controller: _sectionCtrl,
                decoration: const InputDecoration(labelText: 'Section ID')),
            const SizedBox(height: 8),
            TextFormField(
                controller: _rollCtrl,
                decoration: const InputDecoration(labelText: 'Roll')),
            const SizedBox(height: 16),
            ElevatedButton(
                onPressed: _saving ? null : _create,
                child: Text(_saving ? 'Saving...' : 'Create')),
          ]),
        ),
      ),
    );
  }
}
