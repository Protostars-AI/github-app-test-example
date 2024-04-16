const express = require('express');
const app = express();
const bodyParser = require('body-parser');
const authRoutes = require('./routes/auth');
const usersRoutes = require('./routes/users');

app.use(bodyParser.urlencoded({ extended: true }));
app.use(bodyParser.json());

// Routes
app.use('/api/auth', authRoutes);
app.use('/api/users', usersRoutes);

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`Server is running on port ${PORT}`);
});